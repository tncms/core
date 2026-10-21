<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;
use TheNguyen\CMS\Profile\UserProfileManager;

/**
 * P3 — dynamic frontend profile-fields form. Covers rendering, the separate
 * secure update endpoint, typed controls, partial-update/clear semantics,
 * encrypted no-echo, media display-only, localization, and the security
 * negatives (guest, admin-only, system, forged keys, bad values). RBAC is seeded
 * (fail-closed) so owner access comes from ownership, not fail-open.
 */
class AccountProfileFieldsFormTest extends TestCase
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

    private function group(string $key, int $sort = 0, string $source = 'admin'): UserProfileFieldGroup
    {
        return UserProfileFieldGroup::query()->create([
            'key' => $key, 'source' => $source, 'sort_order' => $sort,
            'is_active' => true, 'is_locked' => $source !== 'admin',
        ]);
    }

    private function field(string $key, string $type, UserProfileFieldGroup $group, array $overrides = []): UserProfileField
    {
        return UserProfileField::query()->create(array_merge([
            'key' => $key, 'group_id' => $group->id, 'type' => $type,
            'options' => $overrides['options'] ?? null,
            'visibility' => 'public', 'editability' => 'owner', 'is_encrypted' => false,
            'source' => 'admin', 'sort_order' => 0, 'is_active' => true,
            'is_locked' => false, 'schema_revision' => 1,
        ], array_diff_key($overrides, ['options' => null])));
    }

    private function label(UserProfileField $f, string $locale, string $label): void
    {
        UserProfileFieldTranslation::query()->create([
            'owner_type' => 'field', 'owner_id' => $f->id, 'locale' => $locale, 'label' => $label,
        ]);
    }

    private function submit(User $user, array $profile, array $present): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post('/account/profile/fields', [
            'profile' => $profile, 'profile_present' => $present,
        ]);
    }

    // ---------------- rendering ----------------

    public function test_profile_page_renders_an_editable_custom_field(): void
    {
        $f = $this->field('custom.headline', 'text', $this->group('social'));
        $this->label($f, 'en', 'Headline');

        $this->actingAs(User::factory()->create())->get('/account/profile')
            ->assertOk()
            ->assertSee('Headline', false)
            ->assertSee('name="profile[custom.headline]"', false)
            ->assertSee('name="profile_present[]"', false);
    }

    public function test_no_fields_configured_renders_no_custom_section(): void
    {
        $this->actingAs(User::factory()->create())->get('/account/profile')
            ->assertOk()
            ->assertSee('Save profile', false)      // base form intact
            ->assertDontSee('Additional details', false);
    }

    public function test_inactive_field_is_not_rendered(): void
    {
        $this->field('custom.hidden', 'text', $this->group('social'), ['is_active' => false]);

        $this->actingAs(User::factory()->create())->get('/account/profile')
            ->assertDontSee('name="profile[custom.hidden]"', false);
    }

    public function test_profile_get_is_noindex(): void
    {
        $this->field('custom.headline', 'text', $this->group('social'));
        $this->actingAs(User::factory()->create())->get('/account/profile')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // ---------------- owner writes ----------------

    public function test_owner_saves_a_url_field(): void
    {
        $user = User::factory()->create();
        $this->field('custom.linkedin', 'url', $this->group('social'));

        $this->submit($user, ['custom.linkedin' => 'https://example.com/ada'], ['custom.linkedin'])
            ->assertRedirect(route('cms.account.profile'))
            ->assertSessionHas('status');

        $this->assertSame('https://example.com/ada', $this->manager()->get($user, 'custom.linkedin', $user));
    }

    public function test_invalid_url_scheme_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->field('custom.site', 'url', $this->group('social'));

        $this->submit($user, ['custom.site' => 'javascript:alert(1)'], ['custom.site'])
            ->assertSessionHasErrors('profile.custom.site');

        $this->assertNull($this->manager()->get($user, 'custom.site', $user));
    }

    public function test_select_rejects_a_value_outside_its_choices(): void
    {
        $user = User::factory()->create();
        $this->field('custom.plan', 'select', $this->group('social'), ['options' => ['choices' => ['free' => 'Free', 'pro' => 'Pro']]]);

        $this->submit($user, ['custom.plan' => 'enterprise'], ['custom.plan'])
            ->assertSessionHasErrors('profile.custom.plan');
        $this->assertNull($this->manager()->get($user, 'custom.plan', $user));

        $this->submit($user, ['custom.plan' => 'pro'], ['custom.plan'])->assertSessionHasNoErrors();
        $this->assertSame('pro', $this->manager()->get($user, 'custom.plan', $user));
    }

    public function test_forged_or_unknown_key_is_dropped(): void
    {
        $user = User::factory()->create();
        $this->field('custom.ok', 'text', $this->group('social'));

        // "evil.key" is not a registered editable field — must be ignored, no error, no write.
        $this->submit($user, ['custom.ok' => 'hi', 'evil.key' => 'x'], ['custom.ok', 'evil.key'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('cms.account.profile'));

        $this->assertSame('hi', $this->manager()->get($user, 'custom.ok', $user));
        $this->assertDatabaseMissing('cms_user_profile_values', ['field_key' => 'evil.key']);
    }

    public function test_admin_only_editability_field_is_not_writable_by_owner(): void
    {
        $user = User::factory()->create();
        $this->field('custom.verified', 'boolean', $this->group('social'), ['editability' => 'admin']);

        // Not in the owner's editable set → dropped; manager would also refuse.
        $this->submit($user, ['custom.verified' => '1'], ['custom.verified'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->manager()->get($user, 'custom.verified', $user));
    }

    public function test_system_field_is_never_writable(): void
    {
        $user = User::factory()->create();
        $this->field('custom.score', 'number', $this->group('social'), ['editability' => 'system']);

        $this->submit($user, ['custom.score' => '99'], ['custom.score'])->assertSessionHasNoErrors();
        $this->assertNull($this->manager()->get($user, 'custom.score', $user));
    }

    public function test_readable_but_not_editable_field_renders_read_only(): void
    {
        $user = User::factory()->create();
        $f = $this->field('custom.badge', 'text', $this->group('social'), ['editability' => 'admin']);
        $this->label($f, 'en', 'Badge');
        // give it a value as admin-side so the read-only display has content
        DB::table('cms_user_profile_values')->insert([
            'user_id' => $user->id, 'field_id' => $f->id, 'field_key' => $f->key, 'locale' => '',
            'value' => json_encode('gold'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/account/profile')
            ->assertSee('Badge', false)
            ->assertSee('gold', false)                                  // public visibility → readable
            ->assertDontSee('name="profile[custom.badge]"', false);     // but not an editable input
    }

    public function test_admin_visibility_field_is_omitted_for_owner(): void
    {
        $user = User::factory()->create();
        $f = $this->field('custom.ssn', 'text', $this->group('social'), ['visibility' => 'admin', 'editability' => 'admin']);
        DB::table('cms_user_profile_values')->insert([
            'user_id' => $user->id, 'field_id' => $f->id, 'field_key' => $f->key, 'locale' => '',
            'value' => json_encode('secret-value'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/account/profile')
            ->assertDontSee('secret-value', false)
            ->assertDontSee('custom.ssn', false);
        $this->assertNull($this->manager()->get($user, 'custom.ssn', $user));
    }

    // ---------------- partial / clear / typed semantics ----------------

    public function test_partial_update_does_not_wipe_untouched_fields(): void
    {
        $user = User::factory()->create();
        $g = $this->group('social');
        $this->field('custom.a', 'text', $g);
        $this->field('custom.b', 'text', $g);
        $this->manager()->set($user, ['custom.a' => 'AA', 'custom.b' => 'BB'], $user);

        // submit only A
        $this->submit($user, ['custom.a' => 'AA2'], ['custom.a'])->assertSessionHasNoErrors();

        $this->assertSame('AA2', $this->manager()->get($user, 'custom.a', $user));
        $this->assertSame('BB', $this->manager()->get($user, 'custom.b', $user));
    }

    public function test_empty_text_clears_the_field(): void
    {
        $user = User::factory()->create();
        $this->field('custom.a', 'text', $this->group('social'));
        $this->manager()->set($user, ['custom.a' => 'AA'], $user);

        $this->submit($user, ['custom.a' => ''], ['custom.a'])->assertSessionHasNoErrors();
        $this->assertNull($this->manager()->get($user, 'custom.a', $user));
    }

    public function test_boolean_is_false_when_unchecked_and_true_when_checked(): void
    {
        $user = User::factory()->create();
        $this->field('custom.optin', 'boolean', $this->group('social'));

        // unchecked: present marker only, no value
        $this->submit($user, [], ['custom.optin'])->assertSessionHasNoErrors();
        $this->assertFalse($this->manager()->get($user, 'custom.optin', $user));

        // checked
        $this->submit($user, ['custom.optin' => '1'], ['custom.optin'])->assertSessionHasNoErrors();
        $this->assertTrue($this->manager()->get($user, 'custom.optin', $user));
    }

    public function test_checkbox_stores_array_and_empty_clears(): void
    {
        $user = User::factory()->create();
        $this->field('custom.tags', 'checkbox', $this->group('social'), ['options' => ['choices' => ['a' => 'A', 'b' => 'B', 'c' => 'C']]]);

        $this->submit($user, ['custom.tags' => ['a', 'c']], ['custom.tags'])->assertSessionHasNoErrors();
        $this->assertSame(['a', 'c'], $this->manager()->get($user, 'custom.tags', $user));

        // none checked → present marker only → cleared
        $this->submit($user, [], ['custom.tags'])->assertSessionHasNoErrors();
        $this->assertNull($this->manager()->get($user, 'custom.tags', $user));
    }

    // ---------------- encryption ----------------

    public function test_encrypted_field_blank_keeps_value_and_never_echoes_plaintext(): void
    {
        $user = User::factory()->create();
        $this->field('custom.secret', 'text', $this->group('social'), ['is_encrypted' => true]);
        $this->manager()->set($user, ['custom.secret' => 'hunter2'], $user);

        // GET must not echo the plaintext anywhere
        $this->actingAs($user)->get('/account/profile')->assertDontSee('hunter2', false);

        // blank submit keeps it unchanged
        $this->submit($user, ['custom.secret' => ''], ['custom.secret'])->assertSessionHasNoErrors();
        $this->assertSame('hunter2', $this->manager()->get($user, 'custom.secret', $user));

        // a new non-blank value updates, stored as ciphertext (no plaintext in DB)
        $this->submit($user, ['custom.secret' => 'newpass'], ['custom.secret'])->assertSessionHasNoErrors();
        $this->assertSame('newpass', $this->manager()->get($user, 'custom.secret', $user));
        $raw = DB::table('cms_user_profile_values')->where('field_key', 'custom.secret')->value('value');
        $this->assertStringNotContainsString('newpass', (string) $raw);
    }

    // ---------------- media (scope: display-only) ----------------

    public function test_media_field_is_display_only_and_not_writable(): void
    {
        $user = User::factory()->create();
        $this->field('custom.photo', 'media', $this->group('social'));

        $this->actingAs($user)->get('/account/profile')
            ->assertDontSee('name="profile[custom.photo]"', false);

        $this->submit($user, ['custom.photo' => '5'], ['custom.photo'])->assertSessionHasNoErrors();
        $this->assertNull($this->manager()->get($user, 'custom.photo', $user));
    }

    // ---------------- auth / isolation ----------------

    public function test_guest_is_redirected_from_the_update_endpoint(): void
    {
        $this->field('custom.a', 'text', $this->group('social'));

        $this->post('/account/profile/fields', ['profile' => ['custom.a' => 'x'], 'profile_present' => ['custom.a']])
            ->assertRedirect(route('cms.auth.login'));

        $this->assertDatabaseMissing('cms_user_profile_values', ['field_key' => 'custom.a']);
    }

    public function test_write_targets_only_the_authenticated_owner_ignoring_forged_user_id(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->field('custom.a', 'text', $this->group('social'));

        // a forged user_id in the payload must be ignored — the write is always self
        $this->actingAs($owner)->post('/account/profile/fields', [
            'profile' => ['custom.a' => 'mine'], 'profile_present' => ['custom.a'], 'user_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('mine', $this->manager()->get($owner, 'custom.a', $owner));
        $this->assertNull($this->manager()->get($other, 'custom.a', $other));
    }

    // ---------------- localization ----------------

    public function test_label_localizes_to_current_locale_with_fallback(): void
    {
        $user = User::factory()->create();
        $f = $this->field('custom.headline', 'text', $this->group('social'));
        $this->label($f, 'en', 'Headline');
        $this->label($f, 'vi', 'Tiêu đề');

        app()->setLocale('vi');
        $this->actingAs($user)->get('/account/profile')->assertSee('Tiêu đề', false);
    }

    // ---------------- theme override seam ----------------

    public function test_theme_override_can_render_the_core_profile_fields_partial(): void
    {
        \Illuminate\Support\Facades\View::replaceNamespace('theme', [base_path('tests/Fixtures/p3-theme')]);

        $f = $this->field('custom.headline', 'text', $this->group('social'));
        $this->label($f, 'en', 'Headline');

        $this->actingAs(User::factory()->create())->get('/account/profile')
            ->assertOk()
            ->assertSee('theme-profile-override', false)             // the theme override rendered
            ->assertSee('name="profile[custom.headline]"', false)    // ...and the Core seam inside it
            ->assertSee('Headline', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');     // noindex preserved through the override
    }

    // ---------------- base compatibility ----------------

    public function test_base_profile_update_still_works(): void
    {
        $user = User::factory()->create(['name' => 'Old']);
        $this->field('custom.a', 'text', $this->group('social')); // custom fields present too

        $this->actingAs($user)->post('/account/profile', [
            'name' => 'New Name', 'username' => 'newname',
        ])->assertRedirect(route('cms.account.profile'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'username' => 'newname']);
    }
}
