<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use TheNguyen\CMS\Exceptions\UnknownProfileFieldException;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Profile\UserProfileManager;

/**
 * P1-B Gate 3 — the P0 negative-security matrix enforced centrally by
 * UserProfileManager. Covers cross-user reads/writes, guests, malicious URL
 * schemes, type mismatches, unauthorized bulk writes, unknown/forged keys,
 * inactive fields, encryption-at-rest, N+1 batching, and multi-locale.
 */
class UserProfileSecurityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function mgr(): UserProfileManager
    {
        return app('cms.user_profile');
    }

    // #1 guest reads an owner-visibility field → null (no oracle)
    public function test_01_guest_cannot_read_owner_visibility_field(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        $this->mgr()->set($owner, ['contact.home' => '1 Main St'], $owner);

        $this->assertNull($this->mgr()->get($owner, 'contact.home', null));
        $this->assertArrayNotHasKey('contact.home', $this->mgr()->values($owner, null));
    }

    // #2 another authenticated user cannot read someone's owner field
    public function test_02_other_authenticated_cannot_read_owner_field(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        $other = $this->member();
        $this->mgr()->set($owner, ['contact.home' => '1 Main St'], $owner);

        $this->assertNull($this->mgr()->get($owner, 'contact.home', $other));
    }

    // #3 owner cannot write an admin/system/immutable field
    public function test_03_owner_cannot_write_admin_or_immutable_field(): void
    {
        [$owner] = $this->defineField('sys.badge', 'text', 'public', 'admin');
        $this->member(); // initialize RBAC so owner is not fail-open admin

        $this->expectException(AuthorizationException::class);
        $this->mgr()->set($owner, ['sys.badge' => 'x'], $owner);
    }

    public function test_03b_immutable_is_never_writable(): void
    {
        [$owner] = $this->defineField('sys.uuid', 'text', 'public', 'immutable');
        $this->expectException(AuthorizationException::class);
        $this->mgr()->set($owner, ['sys.uuid' => 'x'], $owner);
    }

    // #4 non-admin cannot write another user's field
    public function test_04_non_admin_cannot_write_other_users_field(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner', 'owner');
        $other = $this->member();

        $this->expectException(AuthorizationException::class);
        $this->mgr()->set($owner, ['contact.home' => 'hack'], $other);
    }

    // #5 forged/unknown key is rejected and nothing is stored
    public function test_05_unknown_key_is_rejected(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        try {
            $this->mgr()->set($owner, ['contact.home' => 'ok', 'forged.key' => 'x'], $owner);
            $this->fail('Expected UnknownProfileFieldException.');
        } catch (UnknownProfileFieldException) {
            // atomic: the valid key must also NOT have been written
            $this->assertNull($this->mgr()->get($owner, 'contact.home', $owner));
        }
    }

    // #6 a reserved identifier is not a registered field → unknown on write
    public function test_06_reserved_identifier_is_not_writable(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        $this->expectException(UnknownProfileFieldException::class);
        $this->mgr()->set($owner, ['email' => 'evil@example.test'], $owner);
    }

    // #7 malicious URL scheme is rejected
    public function test_07_javascript_url_scheme_rejected(): void
    {
        [$owner] = $this->defineField('links.site', 'url', 'public', 'owner');
        $this->expectException(ValidationException::class);
        $this->mgr()->set($owner, ['links.site' => 'javascript:alert(1)'], $owner);
    }

    // #8 HTML in a text field is stored verbatim (escaping is the view's job)
    public function test_08_html_in_text_is_stored_inert_not_executed(): void
    {
        [$owner] = $this->defineField('bio.text', 'textarea', 'public', 'owner');
        $payload = '<script>alert(1)</script>';
        $this->mgr()->set($owner, ['bio.text' => $payload], $owner);
        $this->assertSame($payload, $this->mgr()->get($owner, 'bio.text', $owner)); // literal string, never executed
    }

    // #10 user deletion cascades values (full detail in the FK test)
    public function test_10_user_deletion_cascades_values(): void
    {
        [$owner, $field] = $this->defineField('contact.home', 'text', 'owner');
        $this->mgr()->set($owner, ['contact.home' => 'x'], $owner);
        DB::table('users')->where('id', $owner->id)->delete();
        $this->assertSame(0, DB::table('cms_user_profile_values')->where('field_id', $field->id)->count());
    }

    // #11 inactive (disabled-plugin) field is unknown to writes, hidden to reads
    public function test_11_inactive_field_is_hidden_and_unwritable(): void
    {
        [$owner, $field] = $this->defineField('x.disabled', 'text', 'public');
        $field->update(['is_active' => false]);

        $this->assertNull($this->mgr()->get($owner, 'x.disabled', $owner));
        $this->expectException(UnknownProfileFieldException::class);
        $this->mgr()->set($owner, ['x.disabled' => 'v'], $owner);
    }

    // #12 bulk read across many authors is not N+1
    public function test_12_values_for_many_is_not_n_plus_one(): void
    {
        [$a, $field] = $this->defineField('pub.title', 'text', 'public');
        $b = $this->member();
        $this->mgr()->set($a, ['pub.title' => 'A'], $a);
        $this->mgr()->set($b, ['pub.title' => 'B'], $b);
        $authors = [$a, $b, $this->member(), $this->member(), $this->member()];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = $this->mgr()->valuesForMany($authors, null);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('A', $out[$a->id]['pub.title']);
        $this->assertLessThanOrEqual(2, $queries, 'valuesForMany must batch (fields + values), not query per author.');
    }

    // #13 multi-locale slots are independent
    public function test_13_multi_locale_values_are_independent(): void
    {
        [$owner] = $this->defineField('pub.headline', 'text', 'public', 'owner');
        $this->mgr()->set($owner, ['pub.headline' => 'EN'], $owner, 'en');
        $this->mgr()->set($owner, ['pub.headline' => 'VI'], $owner, 'vi');
        $this->assertSame('EN', $this->mgr()->get($owner, 'pub.headline', $owner, 'en'));
        $this->assertSame('VI', $this->mgr()->get($owner, 'pub.headline', $owner, 'vi'));
    }

    // #14 private values are not exposed via the host model's serialization
    public function test_14_private_value_not_leaked_via_user_serialization(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        $this->mgr()->set($owner, ['contact.home' => 'secret'], $owner);
        $this->assertStringNotContainsString('secret', json_encode($owner->fresh()->toArray()));
    }

    // #15 the manager is the only read path — a guest's values() never includes private
    public function test_15_guest_values_never_include_private_fields(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        $this->defineField('pub.title', 'text', 'public', 'owner', $owner);
        $this->mgr()->set($owner, ['contact.home' => 'secret', 'pub.title' => 'Shown'], $owner);

        $guestView = $this->mgr()->values($owner, null);
        $this->assertSame(['pub.title' => 'Shown'], $guestView);
    }

    // #16 encrypted field: ciphertext at rest, no index, decrypts for authorized reader
    public function test_16_encrypted_field_is_ciphertext_at_rest(): void
    {
        [$owner, $field] = $this->defineField('sec.ssn', 'text', 'owner', 'owner', null, true);
        $this->mgr()->set($owner, ['sec.ssn' => 'SECRET-123'], $owner);

        $raw = DB::table('cms_user_profile_values')->where('field_id', $field->id)->first();
        $this->assertStringNotContainsString('SECRET-123', (string) $raw->value); // encrypted at rest
        $this->assertNull($raw->value_index);                                     // never indexed/searchable
        $this->assertSame('SECRET-123', $this->mgr()->get($owner, 'sec.ssn', $owner)); // decrypts for owner
    }

    // #17 admin with permission may write another user's field; provenance recorded
    public function test_17_admin_with_permission_writes_other_user(): void
    {
        [$owner, $field] = $this->defineField('contact.home', 'text', 'owner', 'owner');
        $admin = $this->userWithPermissions([UserProfileManager::PERM_EDIT_OTHERS]);

        $this->mgr()->set($owner, ['contact.home' => 'set-by-admin'], $admin);

        // edit_others grants WRITE, not READ (view_private is a separate grant):
        // verify the write via the owner's own read + the persisted provenance.
        $this->assertSame('set-by-admin', $this->mgr()->get($owner, 'contact.home', $owner));
        $this->assertNull($this->mgr()->get($owner, 'contact.home', $admin)); // cannot read without view_private
        $row = DB::table('cms_user_profile_values')->where('field_id', $field->id)->first();
        $this->assertSame((int) $admin->id, (int) $row->updated_by);
    }

    // #18 no value cache: an owner value is never served to a guest (keyed by actor)
    public function test_18_owner_value_never_served_to_guest_after_owner_read(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        $this->mgr()->set($owner, ['contact.home' => 'secret'], $owner);
        $this->assertSame('secret', $this->mgr()->get($owner, 'contact.home', $owner)); // warm any cache
        $this->assertNull($this->mgr()->get($owner, 'contact.home', null));             // guest still denied
    }

    // admin with view_private may read another user's owner field
    public function test_admin_with_view_private_reads_other_users_owner_field(): void
    {
        [$owner] = $this->defineField('contact.home', 'text', 'owner');
        $this->mgr()->set($owner, ['contact.home' => 'addr'], $owner);
        $admin = $this->userWithPermissions([UserProfileManager::PERM_VIEW_PRIVATE]);

        $this->assertSame('addr', $this->mgr()->get($owner, 'contact.home', $admin));
    }

    // ---- helpers ----

    /** @return array{0: User, 1: UserProfileField} */
    private function defineField(string $key, string $type, string $vis = 'owner', string $edit = 'owner', ?User $owner = null, bool $enc = false): array
    {
        $owner ??= $this->user();
        $group = UserProfileFieldGroup::query()->firstOrCreate(['key' => explode('.', $key)[0]], ['source' => 'core']);
        $field = UserProfileField::query()->create([
            'key' => $key, 'group_id' => $group->id, 'type' => $type,
            'visibility' => $vis, 'editability' => $edit, 'is_encrypted' => $enc,
            'source' => 'core', 'is_active' => true,
        ]);

        return [$owner, $field];
    }

    private function user(): User
    {
        return User::query()->forceCreate([
            'name' => 'U', 'email' => 'u-'.uniqid().'@example.test', 'password' => bcrypt('secret-password'),
        ]);
    }

    /** A user bound to a non-privileged role so the RBAC system is initialized (no fail-open). */
    private function member(): User
    {
        $u = $this->user();
        $role = Role::query()->firstOrCreate(['slug' => 'member'], ['name' => 'Member']);
        $u->roles()->syncWithoutDetaching([$role->id]);

        return $u;
    }

    /** @param array<int, string> $slugs */
    private function userWithPermissions(array $slugs): User
    {
        app('cms.permission')->syncDefaults(); // persist registered profile_fields.* perms
        $this->member();                        // ensure RBAC initialized

        $u = $this->user();
        $role = Role::query()->firstOrCreate(['slug' => 'profile-admin'], ['name' => 'Profile Admin']);
        $ids = Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->syncWithoutDetaching($ids);
        $u->roles()->syncWithoutDetaching([$role->id]);

        return $u;
    }
}
