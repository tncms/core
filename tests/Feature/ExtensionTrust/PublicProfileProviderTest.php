<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;
use TheNguyen\CMS\Profile\PublicProfileProjection;
use TheNguyen\CMS\Profile\PublicProfileProvider;
use TheNguyen\CMS\Profile\UserProfileManager;

/**
 * CORE-EXTENSION-TRUST S3 — the public profile provider (S3-API-CONTRACT §1-3).
 *
 * The audience is always public: there is no viewer input, so an editor, an
 * admin or the subject themself get exactly what a guest gets. Subjects must
 * have opted in through the Core field `core.public_profile`.
 */
final class PublicProfileProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_EMAIL = 'b-private@s3.test';

    private const SECRET_PHONE = '+84-900-555-0101';

    private const SECRET_USERNAME = 'b_login_handle';

    protected function setUp(): void
    {
        parent::setUp();
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => false, 'is_active' => true]);
        $this->seed(CmsRolePermissionSeeder::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function provider(): PublicProfileProvider
    {
        return app('cms.public_profile');
    }

    private function subject(array $attrs = []): User
    {
        return User::factory()->create($attrs + [
            'name' => 'User B',
            'email' => self::SECRET_EMAIL,
            'phone' => self::SECRET_PHONE,
            'username' => self::SECRET_USERNAME,
            'avatar' => 'https://cdn.s3.test/b.png',
            'bio' => 'Public bio of B.',
        ]);
    }

    private function optIn(User $user, bool $value = true): void
    {
        app('cms.user_profile')->set($user, ['core.public_profile' => $value], $user);
    }

    private function withRole(User $user, string $slug): User
    {
        $role = Role::query()->where('slug', $slug)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function group(string $key = 'social'): UserProfileFieldGroup
    {
        return UserProfileFieldGroup::query()->firstOrCreate(['key' => $key], ['source' => 'admin', 'sort_order' => 0, 'is_active' => true, 'is_locked' => false]);
    }

    private function field(string $key, string $type, array $o = []): UserProfileField
    {
        return UserProfileField::query()->create(array_merge([
            'key' => $key, 'group_id' => $this->group()->id, 'type' => $type, 'options' => null,
            'visibility' => 'public', 'editability' => 'owner', 'is_encrypted' => false,
            'source' => 'admin', 'sort_order' => 0, 'is_active' => true, 'is_locked' => false, 'schema_revision' => 1,
        ], $o));
    }

    private function rawValue(User $u, UserProfileField $f, mixed $value, string $locale = '', ?int $by = null): void
    {
        DB::table('cms_user_profile_values')->updateOrInsert(
            ['user_id' => $u->id, 'field_id' => $f->id, 'locale' => $locale],
            ['field_key' => $f->key, 'value' => json_encode($value), 'updated_by' => $by, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function consentField(): UserProfileField
    {
        return UserProfileField::query()->where('key', 'core.public_profile')->firstOrFail();
    }

    /** @return list<string> */
    private function fieldKeys(?PublicProfileProjection $p): array
    {
        return array_map(static fn (array $f): string => $f['key'], $p?->fields ?? []);
    }

    // ------------------------------------------------------------------
    // Opt-in field (migration)
    // ------------------------------------------------------------------

    public function test_core_migration_seeds_the_locked_opt_in_field_with_safe_defaults(): void
    {
        $f = $this->consentField();

        $this->assertSame('boolean', $f->type);
        $this->assertSame('owner', $f->visibility);
        $this->assertSame('owner', $f->editability);
        $this->assertFalse((bool) $f->is_encrypted);
        $this->assertSame('core', $f->source);
        $this->assertTrue((bool) $f->is_locked);
        $this->assertTrue((bool) $f->is_active);
        $this->assertFalse($f->default_value);

        $group = UserProfileFieldGroup::query()->findOrFail($f->group_id);
        $this->assertSame('core', $group->key);
        $this->assertSame('core', $group->source);

        $labels = UserProfileFieldTranslation::query()->where('owner_type', 'field')->where('owner_id', $f->id)->pluck('label', 'locale')->all();
        $this->assertSame('Show my public profile', $labels['en'] ?? null);
        $this->assertSame('Hiển thị hồ sơ công khai của tôi', $labels['vi'] ?? null);
        $this->assertArrayHasKey('', $labels);
    }

    public function test_opt_in_migration_is_idempotent_and_preserves_consent(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        $migration = require base_path('packages/thenguyen/cms-core/database/migrations/2026_10_25_000001_seed_core_public_profile_field.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, UserProfileField::query()->where('key', 'core.public_profile')->count());
        $this->assertSame(1, UserProfileFieldGroup::query()->where('key', 'core')->count());
        $this->assertNotNull($this->provider()->project($b->id, ['name']));
    }

    public function test_the_consent_flag_never_appears_in_the_p4_presentation_output(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $admin = $this->withRole(User::factory()->create(), 'admin');

        foreach ([null, $b, $admin] as $viewer) {
            $present = cms_profile_present($b, $viewer);
            $this->assertStringNotContainsString('core.public_profile', (string) json_encode($present));
        }

        $this->assertTrue(app('cms.user_profile')->get($b, 'core.public_profile', $b), 'the owner still reads their own flag through the manager');
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    public function test_a_user_who_has_not_opted_in_is_never_projected(): void
    {
        $b = $this->subject();

        $this->assertNull($this->provider()->project($b->id, ['name', 'avatar', 'bio']));
    }

    public function test_an_opted_in_user_gets_the_requested_public_projection(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        $p = $this->provider()->project($b->id, ['name', 'avatar', 'bio']);

        $this->assertInstanceOf(PublicProfileProjection::class, $p);
        $this->assertSame('User B', $p->name);
        $this->assertSame(['url' => 'https://cdn.s3.test/b.png', 'alt' => 'User B'], $p->avatar);
        $this->assertSame('Public bio of B.', $p->bio);
        $this->assertSame([], $p->fields);
    }

    public function test_opting_out_removes_the_projection_immediately(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $this->assertNotNull($this->provider()->project($b->id, ['name']));

        $this->optIn($b, false);

        $this->assertNull($this->provider()->project($b->id, ['name']));
    }

    public function test_missing_subject_and_ineligible_subject_are_indistinguishable(): void
    {
        $b = $this->subject();

        $this->assertNull($this->provider()->project(999999, ['name']));
        $this->assertNull($this->provider()->project($b->id, ['name']));
    }

    /**
     * Each malformed reference is derived from the opted-in subject's real id, so that a lenient parser
     * (cast, trim, numeric-string) would land on an eligible subject. The ids are never assumed: MySQL keeps
     * AUTO_INCREMENT across RefreshDatabase's rolled-back transactions (CORE-RELEASE-BLOCKERS-R1, TD-P12A-1).
     *
     * @return array<string, array{0: \Closure(int): (int|string)}>
     */
    public static function badSubjectRefs(): array
    {
        return [
            'zero' => [static fn (int $id): int => 0],
            'negative' => [static fn (int $id): int => -$id],
            'negative string' => [static fn (int $id): string => '-'.$id],
            'leading space' => [static fn (int $id): string => ' '.$id],
            'trailing newline' => [static fn (int $id): string => $id."
"],
            'exponent' => [static fn (int $id): string => $id.'e0'],
            'hex' => [static fn (int $id): string => '0x'.dechex($id)],
            'decimal' => [static fn (int $id): string => $id.'.0'],
            'empty' => [static fn (int $id): string => ''],
            'alpha' => [static fn (int $id): string => 'abc'],
            'nineteen digits' => [static fn (int $id): string => '1000000000000000001'],
            'leading zeros to nineteen digits' => [static fn (int $id): string => str_pad((string) $id, 19, '0', STR_PAD_LEFT)],
            'plus sign' => [static fn (int $id): string => '+'.$id],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSubjectRefs')]
    public function test_malformed_subject_references_return_null(\Closure $ref): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $this->assertSame('User B', $this->provider()->project($b->id, ['name'])?->name, 'the well-formed id of the same subject is eligible');

        $this->assertNull($this->provider()->project($ref((int) $b->id), ['name']));
    }

    public function test_a_digit_string_subject_reference_is_accepted(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        $this->assertSame('User B', $this->provider()->project((string) $b->id, ['name'])?->name);
    }

    public function test_deactivating_the_opt_in_field_makes_every_subject_ineligible(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $this->consentField()->update(['is_active' => false]);

        $this->assertNull($this->provider()->project($b->id, ['name']));
    }

    public function test_a_tampered_opt_in_field_definition_fails_closed(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $f = $this->consentField();

        $f->update(['source' => 'admin']);
        $this->assertNull($this->provider()->project($b->id, ['name']), 'non-core source');

        $f->update(['source' => 'core', 'is_encrypted' => true]);
        $this->assertNull($this->provider()->project($b->id, ['name']), 'encrypted');

        $f->update(['is_encrypted' => false, 'type' => 'text']);
        $this->assertNull($this->provider()->project($b->id, ['name']), 'non-boolean');
    }

    public function test_only_a_strict_true_neutral_locale_value_counts_as_consent(): void
    {
        $b = $this->subject();
        $f = $this->consentField();

        foreach (['1', 'true', 1, 'yes', ['enc' => 'x']] as $value) {
            $this->rawValue($b, $f, $value, '', $b->id);
            $this->assertNull($this->provider()->project($b->id, ['name']), 'value '.json_encode($value));
        }

        DB::table('cms_user_profile_values')->where('user_id', $b->id)->delete();
        $this->rawValue($b, $f, true, 'vi', $b->id);
        $this->assertNull($this->provider()->project($b->id, ['name']), 'locale-scoped consent is not neutral consent');

        $this->rawValue($b, $f, true, '', null);
        $this->assertNull($this->provider()->project($b->id, ['name']), 'a value with no recorded author is not consent');

        $this->rawValue($b, $f, true, '', $b->id);
        $this->assertNotNull($this->provider()->project($b->id, ['name']));
    }

    public function test_consent_set_on_someone_elses_behalf_does_not_count(): void
    {
        $b = $this->subject();
        $delegate = User::factory()->create(['name' => 'Delegate']);
        $role = Role::query()->firstOrCreate(['slug' => 's3-profile-editor'], ['name' => 'S3 Profile Editor']);
        $delegate->roles()->syncWithoutDetaching([$role->id]);
        app('cms.permission')->syncDefaults();
        $role->permissions()->syncWithoutDetaching(\TheNguyen\CMS\Models\Permission::query()->where('slug', UserProfileManager::PERM_EDIT_OTHERS)->pluck('id')->all());
        $this->assertTrue(cms_can(UserProfileManager::PERM_EDIT_OTHERS, $delegate->fresh()), 'the delegate really may edit others');

        app('cms.user_profile')->set($b, ['core.public_profile' => true], $delegate->fresh());

        $this->assertTrue(app('cms.user_profile')->get($b, 'core.public_profile', $b), 'the write itself was allowed');
        $this->assertNull($this->provider()->project($b->id, ['name']), 'but it is not the subject\'s own consent');

        $this->optIn($b);
        $this->assertNotNull($this->provider()->project($b->id, ['name']));
    }

    // ------------------------------------------------------------------
    // Explicit, bounded selection
    // ------------------------------------------------------------------

    public function test_only_requested_fields_are_returned_and_the_shape_is_stable(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        $p = $this->provider()->project($b->id, ['name']);

        $this->assertSame(['name' => 'User B', 'avatar' => null, 'bio' => null, 'fields' => []], $p?->toArray());
        $this->assertSame($p->toArray(), json_decode((string) json_encode($p), true));
    }

    public function test_unknown_and_private_tokens_are_silently_absent(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        $p = $this->provider()->project($b->id, ['name', 'name', 'email', 'phone', 'username', 'password', 'remember_token', 'roles', 'id', 'field:', 'field:nope.nothing', 'core.public_profile', 'field:core.public_profile']);

        $this->assertSame(['name' => 'User B', 'avatar' => null, 'bio' => null, 'fields' => []], $p?->toArray());
    }

    public function test_selection_bounds_are_programmer_errors_without_profile_data(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        foreach ([
            array_fill(0, PublicProfileProvider::MAX_FIELDS + 1, 'name'),
            ['name', 42],
            ['field:'.str_repeat('a', 160)],
        ] as $fields) {
            try {
                $this->provider()->project($b->id, $fields);
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('User B', $e->getMessage());
                $this->assertStringNotContainsString(self::SECRET_EMAIL, $e->getMessage());
            }
        }

        $this->assertNotNull($this->provider()->project($b->id, array_fill(0, PublicProfileProvider::MAX_FIELDS, 'name')));
    }

    // ------------------------------------------------------------------
    // Custom fields: only what a guest may read through UserProfileManager
    // ------------------------------------------------------------------

    public function test_custom_fields_are_limited_to_public_plain_values(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $this->field('custom.site', 'url', ['visibility' => 'public']);
        $this->field('custom.motto', 'text', ['visibility' => 'authenticated']);
        $this->field('custom.phone', 'text', ['visibility' => 'owner']);
        $this->field('custom.audit', 'text', ['visibility' => 'admin', 'editability' => 'admin']);
        $this->field('custom.secret', 'text', ['visibility' => 'public', 'is_encrypted' => true]);
        $this->field('custom.photo', 'media', ['visibility' => 'public']);
        $this->field('custom.old', 'text', ['visibility' => 'public', 'is_active' => false]);
        app('cms.user_profile')->set($b, ['custom.site' => 'https://b.s3.test', 'custom.motto' => 'm', 'custom.phone' => self::SECRET_PHONE, 'custom.secret' => 'enc-value', 'custom.photo' => 7], $b);
        $this->rawValue($b, UserProfileField::query()->where('key', 'custom.audit')->first(), 'audit-note');
        $this->rawValue($b, UserProfileField::query()->where('key', 'custom.old')->first(), 'old');

        $tokens = array_map(static fn (string $k): string => 'field:'.$k, ['custom.site', 'custom.motto', 'custom.phone', 'custom.audit', 'custom.secret', 'custom.photo', 'custom.old']);
        $p = $this->provider()->project($b->id, $tokens);

        $this->assertSame(['custom.site'], $this->fieldKeys($p));
        $this->assertSame(['key' => 'custom.site', 'type' => 'url', 'label' => 'custom.site', 'display' => 'https://b.s3.test', 'href' => 'https://b.s3.test'], $p->fields[0]);
        $json = (string) json_encode($p);
        foreach ([self::SECRET_PHONE, 'audit-note', 'enc-value', '"m"', 'old'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_custom_field_labels_follow_the_server_locale(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $f = $this->field('custom.site', 'url');
        UserProfileFieldTranslation::query()->create(['owner_type' => 'field', 'owner_id' => $f->id, 'locale' => 'en', 'label' => 'Website']);
        UserProfileFieldTranslation::query()->create(['owner_type' => 'field', 'owner_id' => $f->id, 'locale' => 'vi', 'label' => 'Trang web']);
        app('cms.user_profile')->set($b, ['custom.site' => 'https://b.s3.test'], $b);

        app()->setLocale('vi');
        $vi = $this->provider()->project($b->id, ['field:custom.site']);
        app()->setLocale('en');
        $en = $this->provider()->project($b->id, ['field:custom.site']);

        $this->assertSame('Trang web', $vi->fields[0]['label']);
        $this->assertSame('Website', $en->fields[0]['label']);
        $this->assertSame($vi->fields[0]['href'], $en->fields[0]['href']);
    }

    // ------------------------------------------------------------------
    // Viewer independence (Editor A vs User B)
    // ------------------------------------------------------------------

    public function test_every_viewer_gets_the_guest_projection(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $this->field('custom.site', 'url');
        $this->field('custom.phone', 'text', ['visibility' => 'owner']);
        $this->field('custom.motto', 'text', ['visibility' => 'authenticated']);
        app('cms.user_profile')->set($b, ['custom.site' => 'https://b.s3.test', 'custom.phone' => self::SECRET_PHONE, 'custom.motto' => 'members only'], $b);
        $fields = ['name', 'avatar', 'bio', 'field:custom.site', 'field:custom.phone', 'field:custom.motto'];

        $guest = $this->provider()->project($b->id, $fields)?->toArray();

        $editor = $this->withRole(User::factory()->create(['name' => 'Editor A']), 'editor');
        $admin = $this->withRole(User::factory()->create(['name' => 'Admin C']), 'admin');
        $this->assertTrue(cms_can(UserProfileManager::PERM_VIEW_PRIVATE, $admin), 'the admin really can read private fields elsewhere');

        foreach (['editor' => $editor, 'admin' => $admin, 'owner' => $b] as $label => $viewer) {
            $this->actingAs($viewer);
            $this->assertSame($guest, $this->provider()->project($b->id, $fields)?->toArray(), $label);
        }

        $this->assertSame(['custom.site'], array_column($guest['fields'], 'key'));
    }

    public function test_editor_a_cannot_reach_user_b_private_data_through_any_selection(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $this->field('custom.phone', 'text', ['visibility' => 'owner']);
        app('cms.user_profile')->set($b, ['custom.phone' => self::SECRET_PHONE], $b);
        $editor = $this->withRole(User::factory()->create(['name' => 'Editor A']), 'editor');
        $this->actingAs($editor);

        $p = $this->provider()->project($b->id, ['name', 'email', 'phone', 'username', 'field:custom.phone', 'field:email', 'field:phone', 'timezone', 'frontend_last_login_ip']);
        $json = (string) json_encode($p);

        foreach ([self::SECRET_EMAIL, self::SECRET_PHONE, self::SECRET_USERNAME, '$2y$'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_the_projection_never_carries_a_model_or_credential(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        $p = $this->provider()->project($b->id, ['name', 'avatar', 'bio']);
        $reflection = new \ReflectionClass($p);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($p);
            $this->assertTrue($value === null || is_string($value) || is_array($value), $property->getName());
        }

        $serialized = serialize($p).json_encode($p).var_export($p->toArray(), true);
        foreach ([self::SECRET_EMAIL, self::SECRET_PHONE, self::SECRET_USERNAME, (string) $b->getAuthPassword(), 'App\\Models\\User', 'remember_token'] as $needle) {
            $this->assertStringNotContainsString($needle, $serialized);
        }
    }

    // ------------------------------------------------------------------
    // Output safety
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
            'data' => ['data:image/svg+xml,<svg onload=alert(1)>'],
            'protocol relative' => ['//evil.s3.test/a.png'],
            'userinfo' => ['https://user:pass@cdn.s3.test/a.png'],
            'user only' => ['https://user@cdn.s3.test/a.png'],
            'ftp' => ['ftp://cdn.s3.test/a.png'],
            'space' => ['https://cdn.s3.test/a b.png'],
            'control char' => ["https://cdn.s3.test/a\x01.png"],
            'tab' => ["https://cdn.s3.test/\ta.png"],
            'no host' => ['https:///a.png'],
            'relative' => ['/storage/a.png'],
            'too long' => ['https://cdn.s3.test/'.str_repeat('a', 2050)],
            'backslash host' => ['http://good.s3.test\.evil.test/a.png'],
            'backslash path' => ['https://cdn.s3.test/a\b.png'],
            'nbsp host' => ["https://\u{00A0}cdn.s3.test/a.png"],
            'line separator' => ["https://cdn.s3.test\u{2028}/a.png"],
            'unicode host' => ['https://exämple.s3.test/a.png'],
            'underscore-free garbage host' => ['https://-bad-.s3.test/a.png'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeUrls')]
    public function test_unsafe_avatar_urls_are_dropped(string $url): void
    {
        $b = $this->subject();
        if (DB::getDriverName() !== 'sqlite' && strlen($url) > 255) {
            // `users.avatar` is a 255-character column. A driver that enforces the length (MySQL strict mode)
            // cannot store this value at all, which is the stronger guarantee; the provider's own length guard
            // is exercised on SQLite, which does not enforce VARCHAR lengths (TD-P12A-1).
            $this->expectException(\Illuminate\Database\QueryException::class);
            DB::table('users')->where('id', $b->id)->update(['avatar' => $url]);

            return;
        }
        DB::table('users')->where('id', $b->id)->update(['avatar' => $url]); // bypass write-time validation
        $this->optIn($b);

        $p = $this->provider()->project($b->id, ['name', 'avatar']);

        $this->assertNull($p?->avatar);
        $this->assertSame('User B', $p?->name);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeUrls')]
    public function test_unsafe_custom_url_values_lose_their_href_but_stay_plain_text(string $url): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $f = $this->field('custom.site', 'url');
        $this->rawValue($b, $f, $url);

        $p = $this->provider()->project($b->id, ['field:custom.site']);

        $this->assertCount(1, $p?->fields ?? []);
        $this->assertArrayHasKey('href', $p->fields[0]);
        $this->assertNull($p->fields[0]['href']);
        $this->assertSame($url, $p->fields[0]['display']);
    }

    public function test_well_formed_hosts_are_accepted(): void
    {
        foreach (['https://cdn.s3.test/b.png', 'http://127.0.0.1:8863/a.svg', 'https://[::1]/a.png', 'https://xn--exmple-cua.s3.test/a.png?x=1#f'] as $url) {
            $b = $this->subject(['email' => uniqid().'@s3.test', 'username' => null, 'avatar' => $url]);
            $this->optIn($b);
            $this->assertSame($url, $this->provider()->project($b->id, ['avatar'])?->avatar['url'] ?? null, $url);
        }
    }

    public function test_markup_in_text_is_returned_as_plain_text_and_escaped_by_blade(): void
    {
        $b = $this->subject(['name' => '<script>alert(1)</script>B', 'bio' => '<img src=x onerror=alert(2)>bio']);
        $this->optIn($b);

        $p = $this->provider()->project($b->id, ['name', 'bio']);
        $html = Blade::render('<h2>{{ $p->name }}</h2><p>{{ $p->bio }}</p>', ['p' => $p]);

        $this->assertSame('<script>alert(1)</script>B', $p->name);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_long_bio_is_bounded_and_blank_values_are_null(): void
    {
        $b = $this->subject(['bio' => str_repeat('é', 6000), 'name' => '   ']);
        $this->optIn($b);

        $p = $this->provider()->project($b->id, ['name', 'bio']);

        $this->assertNull($p->name);
        $this->assertSame(5000, mb_strlen((string) $p->bio));
    }

    // ------------------------------------------------------------------
    // Failure, repetition, isolation
    // ------------------------------------------------------------------

    public function test_an_internal_failure_fails_closed_and_logs_no_values(): void
    {
        $b = $this->subject();
        $this->optIn($b);
        $this->field('custom.site', 'url');

        $this->app->instance('cms.user_profile', new class extends UserProfileManager
        {
            public function __construct() {}

            public function values(\Illuminate\Database\Eloquent\Model $target, ?\Illuminate\Contracts\Auth\Authenticatable $actor, ?string $locale = null): array
            {
                throw new \RuntimeException('boom '.self::class);
            }

            public function valuesForMany(iterable $targets, ?\Illuminate\Contracts\Auth\Authenticatable $actor, ?string $locale = null): array
            {
                throw new \RuntimeException('boom many');
            }
        });
        $this->app->forgetInstance(\TheNguyen\CMS\Profile\ProfilePresenter::class);

        Log::spy();

        $this->assertNull($this->provider()->project($b->id, ['name', 'field:custom.site']));

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            $dump = $message.json_encode($context);

            return str_contains($message, 'Public profile projection failed')
                && ($context['exception'] ?? null) === \RuntimeException::class
                && ! str_contains($dump, 'User B')
                && ! str_contains($dump, 'boom');
        })->once();
    }

    public function test_repeated_calls_are_deterministic_and_never_stale(): void
    {
        $b = $this->subject();
        $this->optIn($b);

        $first = $this->provider()->project($b->id, ['name', 'bio']);
        $this->assertEquals($first, $this->provider()->project($b->id, ['name', 'bio']));

        $b->forceFill(['name' => 'User B renamed'])->save();
        $this->assertSame('User B renamed', $this->provider()->project($b->id, ['name'])?->name);

        $this->optIn($b, false);
        $this->assertNull($this->provider()->project($b->id, ['name']));
    }

    public function test_the_provider_singleton_holds_no_per_subject_state(): void
    {
        $b = $this->subject();
        $c = $this->subject(['name' => 'User C', 'email' => 'c@s3.test', 'username' => 'c_login']);
        $this->optIn($b);

        $provider = $this->provider();
        $provider->project($b->id, ['name', 'bio']);

        $this->assertNull($provider->project($c->id, ['name', 'bio']), 'C never opted in; nothing of B may carry over');
        $this->assertSame($provider, app(PublicProfileProvider::class));
    }
}
