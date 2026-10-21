<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * P2 Slice A — CORE-ACCOUNT-SESSIONS-VIEW-SEAM.
 *
 * The account sessions page now resolves theme-first
 * (theme::account.sessions → cms::account.sessions) through SystemViewResolver
 * and is served via renderNoindexResponse('sessions'), exactly like the
 * profile/security/preferences pages. This is a presentation-only seam:
 * logoutOthers, its current-password gate, CSRF and throttle are unchanged.
 */
class AccountSessionsViewSeamTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'tests/Fixtures/system-view-theme';

    private const BROKEN = 'tests/Fixtures/system-view-theme-broken';

    private function useTheme(string $path): void
    {
        View::replaceNamespace('theme', [base_path($path)]);
    }

    public function test_sessions_key_is_registered_in_the_resolver(): void
    {
        $r = app('cms.system_view');
        $this->assertTrue($r->has('sessions'));
        $this->assertSame('theme::account.sessions', $r->themeView('sessions'));
        $this->assertSame('cms::account.sessions', $r->fallbackView('sessions'));
    }

    public function test_fallback_renders_core_view_with_noindex_header(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/sessions')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertSee(route('cms.account.sessions.logout-others'), false)
            ->assertSee('name="_token"', false);
    }

    public function test_theme_override_wins_and_is_noindex_via_header_only(): void
    {
        $user = User::factory()->create();
        $this->useTheme(self::THEME);

        $this->actingAs($user)->get('/account/sessions')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-sessions-override', false)
            ->assertDontSee('name="robots"', false); // header is the authority
    }

    public function test_broken_theme_override_falls_back_and_stays_reachable(): void
    {
        $user = User::factory()->create();
        $this->useTheme(self::BROKEN);

        $this->actingAs($user)->get('/account/sessions')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertDontSee('should-never-render', false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/account/sessions')->assertRedirect(route('cms.auth.login'));
    }

    public function test_logout_others_still_requires_correct_current_password(): void
    {
        $user = User::factory()->create(); // factory password is 'password'

        // Wrong password: rejected with a validation error and NO success flash —
        // the current-password gate is unchanged by this presentation-only seam.
        $this->from('/account/sessions')
            ->actingAs($user)
            ->post('/account/sessions/logout-others', ['current_password' => 'wrong'])
            ->assertRedirect('/account/sessions')
            ->assertSessionHasErrors('current_password')
            ->assertSessionMissing('status');

        // Correct password: the action proceeds and redirects back with a status.
        $this->actingAs($user)
            ->post('/account/sessions/logout-others', ['current_password' => 'password'])
            ->assertRedirect(route('cms.account.sessions'))
            ->assertSessionHas('status');
    }
}
