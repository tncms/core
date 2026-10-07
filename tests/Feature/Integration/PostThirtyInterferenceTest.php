<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Filament\Admin\Pages\ThemeOptionsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Services\ThemeOptionManager;
use TheNguyen\CMS\Services\UserDeletionManager;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionContext;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionVeto;

/**
 * CORE-POST-30-INTEGRATION-R1 — both independently certified lines
 * (CORE-USER-LIFECYCLE-1 and CORE-THEME-OPTIONS-UX-1) coexist in one app boot and
 * do not interfere. These are minimal independence proofs — the two features
 * share no coupling by design.
 */
final class PostThirtyInterferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_authorities_resolve_in_the_same_boot(): void
    {
        $this->assertInstanceOf(UserDeletionManager::class, app('cms.user.deletion'));
        $this->assertInstanceOf(ThemeOptionManager::class, app('cms.theme_option'));
        $this->assertTrue(class_exists(ThemeOptionsPage::class));

        // Distinct singletons, both wired.
        $this->assertSame(app('cms.user.deletion'), app('cms.user.deletion'));
        $this->assertNotSame(app('cms.user.deletion'), app('cms.theme_option'));
    }

    public function test_theme_save_then_user_veto_leaves_both_intact_and_resaveable(): void
    {
        $options = app('cms.theme_option');

        // Save a Theme Option (persistence layer the tabbed page uses).
        $options->set('primary_color', '#abcdef', 'default');
        $this->assertSame('#abcdef', $options->get('primary_color', null, 'default'));

        // A synthetic user-deletion veto fires.
        app('cms.user.deletion')->registerPreDelete(
            fn (UserDeletionContext $ctx) => UserDeletionVeto::make('cert.block', 'Blocked.'),
        );
        $user = User::query()->create([
            'name' => 'U', 'email' => 'u@example.test', 'password' => bcrypt('secret-password'),
        ]);
        $result = app('cms.user.deletion')->delete($user);

        // User remains (veto) …
        $this->assertTrue($result->wasVetoed());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        // … the Theme Option is untouched by the lifecycle …
        $this->assertSame('#abcdef', $options->get('primary_color', null, 'default'));
        // … and Theme Options can be saved again afterwards.
        $options->set('primary_color', '#123123', 'default');
        $this->assertSame('#123123', $options->get('primary_color', null, 'default'));
    }

    public function test_pre_grouping_saved_values_survive_the_grouped_schema(): void
    {
        $options = app('cms.theme_option');

        // Simulate a value saved under .30 (ungrouped schema): stored directly
        // under the unchanged setting key theme_options.default.{key}.
        settings()->set('theme_options.default.footer_text', 'Legacy footer', null, [
            'is_public' => true, 'autoload' => true,
        ]);

        // After .30 -> grouped Default schema, the SAME key/value loads: grouping
        // is presentation metadata, never storage identity.
        $this->assertSame('Legacy footer', $options->get('footer_text', null, 'default'));
        $this->assertSame('Legacy footer', $options->all('default')['footer_text']);

        // Default theme is now grouped (Brand/Colors/Layout/SEO & Social).
        $groups = array_map(fn (array $t): string => $t['group'], $options->groupedSchema('default'));
        $this->assertSame(['brand', 'colors', 'layout', 'seo'], $groups);

        // Saving through the (now tabbed) layer keeps the same key; only the
        // intentional edit changes the value.
        $options->set('footer_text', 'New footer', 'default');
        $this->assertSame('New footer', $options->get('footer_text', null, 'default'));
        $this->assertTrue(settings()->has('theme_options.default.footer_text'));
        // An unrelated option is unchanged by the edit.
        $this->assertSame('#2563eb', $options->get('primary_color', null, 'default'));
    }

    public function test_theme_options_empty_result_does_not_disturb_lifecycle(): void
    {
        // Register the lifecycle handler first.
        app('cms.user.deletion')->registerPreDelete(
            fn (UserDeletionContext $ctx) => UserDeletionVeto::make('cert.block', 'Blocked.'),
        );
        $this->assertSame(1, app('cms.user.deletion')->preDeleteHandlerCount());

        // A Theme Options query that yields nothing (unknown theme) must fail
        // safely and never touch the deletion registry.
        $this->assertSame([], app('cms.theme_option')->groupedSchema('does-not-exist'));

        // The lifecycle handler is still registered and still vetoes.
        $this->assertSame(1, app('cms.user.deletion')->preDeleteHandlerCount());
        $user = User::query()->create([
            'name' => 'U2', 'email' => 'u2@example.test', 'password' => bcrypt('secret-password'),
        ]);
        $this->assertTrue(app('cms.user.deletion')->delete($user)->wasVetoed());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
