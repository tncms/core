<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Profile\ProfilePresenter;
use TheNguyen\CMS\Profile\UserProfileFieldRegistry;
use TheNguyen\CMS\Profile\UserProfileManager;

/**
 * P4 — profile presentation API (viewer-context, privacy-first), Core display
 * partials, and the extension-registry contract. Authorization is proven to come
 * entirely from UserProfileManager; encrypted/media are never presented; output
 * is escaped and URL schemes are re-checked at display.
 */
class ProfilePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => false, 'is_active' => true]);
        $this->seed(CmsRolePermissionSeeder::class); // RBAC fail-closed
    }

    private function manager(): UserProfileManager
    {
        return app('cms.user_profile');
    }

    private function presenter(): ProfilePresenter
    {
        return app(ProfilePresenter::class);
    }

    private function group(string $key, int $sort = 0): UserProfileFieldGroup
    {
        return UserProfileFieldGroup::query()->create(['key' => $key, 'source' => 'admin', 'sort_order' => $sort, 'is_active' => true, 'is_locked' => false]);
    }

    private function field(string $key, string $type, UserProfileFieldGroup $g, array $o = []): UserProfileField
    {
        return UserProfileField::query()->create(array_merge([
            'key' => $key, 'group_id' => $g->id, 'type' => $type, 'options' => $o['options'] ?? null,
            'visibility' => 'public', 'editability' => 'owner', 'is_encrypted' => false,
            'source' => 'admin', 'sort_order' => 0, 'is_active' => true, 'is_locked' => false, 'schema_revision' => 1,
        ], array_diff_key($o, ['options' => null])));
    }

    /** Store a value row directly (bypasses the manager — used to seed arbitrary states). */
    private function rawValue(User $u, UserProfileField $f, mixed $value, string $locale = ''): void
    {
        DB::table('cms_user_profile_values')->insert([
            'user_id' => $u->id, 'field_id' => $f->id, 'field_key' => $f->key, 'locale' => $locale,
            'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function keysOf(array $present): array
    {
        $keys = [];
        foreach ($present['groups'] as $g) {
            foreach ($g['fields'] as $f) {
                $keys[] = $f['key'];
            }
        }

        return $keys;
    }

    // ---------------- Gate B: viewer-context authorization ----------------

    public function test_guest_viewer_sees_only_public_fields(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $pub = $this->field('custom.site', 'url', $g, ['visibility' => 'public']);
        $auth = $this->field('custom.motto', 'text', $g, ['visibility' => 'authenticated']);
        $own = $this->field('custom.phone', 'text', $g, ['visibility' => 'owner']);
        $this->manager()->set($target, ['custom.site' => 'https://ada.dev', 'custom.motto' => 'hi', 'custom.phone' => '123'], $target);

        $present = $this->presenter()->present($target, null); // guest viewer

        $this->assertSame(['custom.site'], $this->keysOf($present));
    }

    public function test_authenticated_other_viewer_sees_public_and_authenticated_not_owner(): void
    {
        $target = User::factory()->create();
        $viewer = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.site', 'url', $g, ['visibility' => 'public']);
        $this->field('custom.motto', 'text', $g, ['visibility' => 'authenticated']);
        $this->field('custom.phone', 'text', $g, ['visibility' => 'owner']);
        $this->manager()->set($target, ['custom.site' => 'https://ada.dev', 'custom.motto' => 'hi', 'custom.phone' => '123'], $target);

        $keys = $this->keysOf($this->presenter()->present($target, $viewer));

        sort($keys);
        $this->assertSame(['custom.motto', 'custom.site'], $keys);
    }

    public function test_owner_sees_own_owner_visibility_but_not_admin_visibility(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.phone', 'text', $g, ['visibility' => 'owner']);
        $admin = $this->field('custom.note', 'text', $g, ['visibility' => 'admin', 'editability' => 'admin']);
        $this->manager()->set($target, ['custom.phone' => '123'], $target);
        $this->rawValue($target, $admin, 'internal');

        $keys = $this->keysOf($this->presenter()->present($target, $target)); // viewer = owner

        $this->assertContains('custom.phone', $keys);
        $this->assertNotContains('custom.note', $keys);
    }

    public function test_admin_viewer_with_view_private_sees_owner_and_admin_fields_of_others(): void
    {
        $target = User::factory()->create();
        $admin = User::factory()->create();
        Role::query()->where('slug', 'super-admin')->first()->users()->syncWithoutDetaching([$admin->id]);
        $g = $this->group('social');
        $this->field('custom.phone', 'text', $g, ['visibility' => 'owner']);
        $note = $this->field('custom.note', 'text', $g, ['visibility' => 'admin', 'editability' => 'admin']);
        $this->manager()->set($target, ['custom.phone' => '123'], $target);
        $this->rawValue($target, $note, 'internal');

        $keys = $this->keysOf($this->presenter()->present($target, $admin));

        $this->assertContains('custom.phone', $keys);
        $this->assertContains('custom.note', $keys);
    }

    public function test_encrypted_field_is_never_presented_even_to_the_owner(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.secret', 'text', $g, ['is_encrypted' => true, 'visibility' => 'public']);
        $this->manager()->set($target, ['custom.secret' => 'topsecret'], $target);

        $present = $this->presenter()->present($target, $target);

        $this->assertNotContains('custom.secret', $this->keysOf($present));
        $html = View::make('cms::profile.fields', ['target' => $target, 'viewer' => $target])->render();
        $this->assertStringNotContainsString('topsecret', $html);
    }

    public function test_media_field_is_omitted_from_presentation(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $m = $this->field('custom.photo', 'media', $g);
        $this->rawValue($target, $m, 7);

        $this->assertNotContains('custom.photo', $this->keysOf($this->presenter()->present($target, $target)));
    }

    public function test_inactive_field_is_omitted(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $f = $this->field('custom.x', 'text', $g, ['is_active' => false]);
        $this->rawValue($target, $f, 'v');

        $this->assertNotContains('custom.x', $this->keysOf($this->presenter()->present($target, $target)));
    }

    public function test_groups_and_fields_are_ordered_and_choice_labels_shown(): void
    {
        $target = User::factory()->create();
        $g2 = $this->group('b-group', 20);
        $g1 = $this->group('a-group', 10);
        $sel = $this->field('custom.plan', 'select', $g1, ['options' => ['choices' => ['free' => 'Free plan', 'pro' => 'Pro plan']]]);
        $this->field('custom.city', 'text', $g2);
        $this->manager()->set($target, ['custom.plan' => 'pro', 'custom.city' => 'Hanoi'], $target);

        $present = $this->presenter()->present($target, $target);

        $this->assertSame(['a-group', 'b-group'], array_column($present['groups'], 'key'));
        $plan = collect($present['groups'][0]['fields'])->firstWhere('key', 'custom.plan');
        $this->assertSame('Pro plan', $plan['display']); // label, not the raw "pro"
    }

    public function test_url_scheme_is_rechecked_at_display(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $bad = $this->field('custom.evil', 'url', $g);
        $this->rawValue($target, $bad, 'javascript:alert(1)'); // bypasses write validation

        $present = $this->presenter()->present($target, $target);
        $field = collect($present['groups'][0]['fields'])->firstWhere('key', 'custom.evil');

        $this->assertNull($field['href']); // not a safe link
        $html = View::make('cms::profile.fields', ['target' => $target, 'viewer' => $target])->render();
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_zero_and_false_are_shown_but_empty_is_omitted(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.count', 'number', $g);
        $this->field('custom.optin', 'boolean', $g);
        $this->field('custom.blank', 'text', $g);
        $this->manager()->set($target, ['custom.count' => 0, 'custom.optin' => false, 'custom.blank' => ''], $target);

        $present = $this->presenter()->present($target, $target);
        $keys = $this->keysOf($present);

        $this->assertContains('custom.count', $keys);
        $this->assertContains('custom.optin', $keys);
        $this->assertNotContains('custom.blank', $keys); // empty omitted
        $optin = collect($present['groups'][0]['fields'])->firstWhere('key', 'custom.optin');
        $this->assertSame('No', $optin['display']);
    }

    public function test_present_many_is_batched_without_n_plus_1(): void
    {
        $g = $this->group('social');
        $this->field('custom.site', 'url', $g, ['visibility' => 'public']);
        $users = User::factory()->count(12)->create();
        foreach ($users as $u) {
            $this->manager()->set($u, ['custom.site' => 'https://u'.$u->id.'.dev'], $u);
        }

        $queriesFor = function (int $n) use ($users): int {
            $subset = $users->slice(0, $n)->values()->all();
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            app(ProfilePresenter::class)->presentMany($subset, null); // fresh presenter (fresh caches)
            $c = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();

            return $c;
        };

        $queriesFor(2); // warm app-level caches (languages/settings) so we measure steady state

        $small = $queriesFor(3);
        $large = $queriesFor(10);

        // Steady-state query count must NOT grow with the number of users (no N+1).
        $this->assertSame($small, $large, "query count changed from {$small} (3 users) to {$large} (10 users)");
        $this->assertLessThanOrEqual(8, $large);
    }

    public function test_only_filter_restricts_keys(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.a', 'text', $g);
        $this->field('custom.b', 'text', $g);
        $this->manager()->set($target, ['custom.a' => 'A', 'custom.b' => 'B'], $target);

        $this->assertSame(['custom.a'], $this->keysOf($this->presenter()->present($target, $target, ['custom.a'])));
    }

    // ---------------- Gate B: helpers ----------------

    public function test_user_profile_value_helper_respects_viewer_and_hides_sensitive(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.site', 'url', $g, ['visibility' => 'public']);
        $this->field('custom.phone', 'text', $g, ['visibility' => 'owner']);
        $this->field('custom.secret', 'text', $g, ['is_encrypted' => true, 'visibility' => 'public']);
        $this->manager()->set($target, ['custom.site' => 'https://ada.dev', 'custom.phone' => '123', 'custom.secret' => 's3cr3t'], $target);

        $this->assertSame('https://ada.dev', user_profile_value($target, 'custom.site', null)); // guest sees public
        $this->assertNull(user_profile_value($target, 'custom.phone', null));                   // owner-only hidden from guest
        $this->assertNull(user_profile_value($target, 'custom.secret', $target));               // encrypted never via presentation helper
        $this->assertNull(user_profile_value($target, 'custom.unknown', $target));
    }

    public function test_cms_profile_present_defaults_viewer_to_current_user_not_target(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.phone', 'text', $g, ['visibility' => 'owner']);
        $this->manager()->set($target, ['custom.phone' => '123'], $target);

        // No authenticated user ⇒ guest viewer ⇒ owner-only field hidden (no viewer=target fallback).
        $this->assertNotContains('custom.phone', $this->keysOf(cms_profile_present($target)));

        // Authenticated AS the target ⇒ owner sees it.
        $this->actingAs($target);
        $this->assertContains('custom.phone', $this->keysOf(cms_profile_present($target)));
    }

    // ---------------- Gate C: Blade partials ----------------

    public function test_fields_partial_escapes_html_and_renders_links_safely(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.bio', 'text', $g, ['visibility' => 'public']);
        $this->field('custom.site', 'url', $g, ['visibility' => 'public']);
        $this->manager()->set($target, ['custom.bio' => '<script>alert(1)</script>', 'custom.site' => 'https://ada.dev'], $target);

        $html = View::make('cms::profile.fields', ['target' => $target, 'viewer' => null])->render();

        $this->assertNoRawScript($html);
        $this->assertStringContainsString('&lt;script&gt;', $html);                 // escaped text
        $this->assertStringContainsString('href="https://ada.dev"', $html);         // safe link
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    public function test_links_partial_renders_only_readable_url_fields(): void
    {
        $target = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.site', 'url', $g, ['visibility' => 'public']);
        $this->field('custom.secret_url', 'url', $g, ['visibility' => 'owner']);
        $this->manager()->set($target, ['custom.site' => 'https://ada.dev', 'custom.secret_url' => 'https://secret.dev'], $target);

        $html = View::make('cms::profile.links', ['target' => $target, 'viewer' => null])->render();

        $this->assertStringContainsString('https://ada.dev', $html);
        $this->assertStringNotContainsString('https://secret.dev', $html); // owner-only, hidden from guest
    }

    public function test_partials_render_empty_when_nothing_is_readable(): void
    {
        $target = User::factory()->create();
        $html = View::make('cms::profile.fields', ['target' => $target, 'viewer' => null])->render();
        $this->assertSame('', trim($html));
    }

    // ---------------- Gate D: extension registry ----------------

    public function test_synthetic_provider_registers_and_reconciles_a_field(): void
    {
        /** @var UserProfileFieldRegistry $reg */
        $reg = app('cms.user_profile_fields');
        $reg->registerGroup('professional', ['sort_order' => 30], 'plugin:demo');
        $reg->registerField('professional.title', [
            'group' => 'professional', 'type' => 'text', 'visibility' => 'public', 'editability' => 'owner',
        ], 'plugin:demo');
        $reg->reconcile();

        $this->assertDatabaseHas('cms_user_profile_fields', ['key' => 'professional.title', 'source' => 'plugin:demo']);

        $target = User::factory()->create();
        $this->manager()->set($target, ['professional.title' => 'Engineer'], $target);
        $this->assertContains('professional.title', $this->keysOf($this->presenter()->present($target, null)));
    }

    public function test_reserved_namespace_registration_is_rejected(): void
    {
        $reg = app('cms.user_profile_fields');
        $this->expectException(\TheNguyen\CMS\Exceptions\ReservedProfileFieldKeyException::class);
        $reg->registerField('core.x', ['group' => 'g', 'type' => 'text'], 'plugin:demo');
    }

    public function test_cross_source_collision_is_rejected(): void
    {
        $reg = app('cms.user_profile_fields');
        $reg->registerGroup('g', [], 'plugin:a');
        $reg->registerField('shared.k', ['group' => 'g', 'type' => 'text', 'visibility' => 'public', 'editability' => 'owner'], 'plugin:a');
        $this->expectException(\TheNguyen\CMS\Exceptions\ProfileFieldCollisionException::class);
        $reg->registerField('shared.k', ['group' => 'g', 'type' => 'text'], 'plugin:b');
    }

    public function test_same_source_reregistration_is_idempotent(): void
    {
        $reg = app('cms.user_profile_fields');
        $reg->registerGroup('g', [], 'plugin:a');
        $def = ['group' => 'g', 'type' => 'text', 'visibility' => 'public', 'editability' => 'owner'];
        $reg->registerField('demo.k', $def, 'plugin:a');
        $reg->registerField('demo.k', $def, 'plugin:a'); // no throw
        $this->assertTrue($reg->has('demo.k'));
    }

    public function test_registration_does_not_grant_rbac_or_make_private_public(): void
    {
        $reg = app('cms.user_profile_fields');
        $reg->registerGroup('g', [], 'plugin:a');
        $reg->registerField('demo.secret', ['group' => 'g', 'type' => 'text', 'visibility' => 'owner', 'editability' => 'owner'], 'plugin:a');
        $reg->reconcile();

        $target = User::factory()->create();
        $this->manager()->set($target, ['demo.secret' => 'hidden'], $target);

        // registered owner-visibility field is still hidden from a guest viewer
        $this->assertNotContains('demo.secret', $this->keysOf($this->presenter()->present($target, null)));
    }

    private function assertNoRawScript(string $html): void
    {
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
