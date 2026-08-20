<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use TheNguyen\CMS\Localization\Events\LocaleChanged;
use TheNguyen\CMS\Localization\SafeInternalRedirect;
use TheNguyen\CMS\Services\LocalePreferenceManager;
use Tests\TestCase;

/**
 * CORE-L10N A2 — the canonical locale transition runtime (cms.locale.switch).
 *
 * Proves ONE transition authority: validate (reject inactive) → resolve a safe
 * redirect → persist through the ONE LocalePreferenceManager → refresh the
 * current locale → emit the canonical LocaleChanged event. Plugins only POST a
 * request here; they never implement switching.
 */
final class LocaleTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.language')->setCurrent('en');
    }

    private function switch(array $body)
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)->post('/locale/switch', $body);
    }

    // ── the ONE redirect authority (open-redirect protection) ──────────────────

    public function test_safe_redirect_rejects_unsafe_targets(): void
    {
        $r = new SafeInternalRedirect;

        $this->assertSame('/blog/x', $r->resolve('/blog/x'));       // valid internal path
        $this->assertSame('/', $r->resolve('//evil.test'));          // protocol-relative
        $this->assertSame('/', $r->resolve('/\\evil.test'));         // back-slash variant
        $this->assertSame('/', $r->resolve('https://evil.test'));    // absolute / scheme
        $this->assertSame('/', $r->resolve("/blog\r\nSet-Cookie:x")); // CRLF injection
        $this->assertSame('/', $r->resolve(null));                   // missing
        $this->assertSame('/', $r->resolve(''));                     // empty
    }

    // ── valid switch: persist + refresh + event + safe redirect ────────────────

    public function test_valid_switch_persists_emits_and_redirects(): void
    {
        $captured = null;
        Event::listen(LocaleChanged::class, function (LocaleChanged $e) use (&$captured): void {
            $captured = $e;
        });

        $response = $this->switch(['locale' => 'vi', 'redirect' => '/blog']);

        $response->assertRedirect('/blog');
        $response->assertSessionHas(LocalePreferenceManager::FRONTEND_SESSION_KEY, 'vi');

        $this->assertInstanceOf(LocaleChanged::class, $captured);
        $this->assertSame('en', $captured->previousLocale);
        $this->assertSame('vi', $captured->newLocale);
    }

    // ── rejected: inactive locale changes nothing, still redirects safely ──────

    public function test_inactive_locale_is_rejected_without_state_change(): void
    {
        Event::fake([LocaleChanged::class]);

        $response = $this->switch(['locale' => 'zz', 'redirect' => '/blog']);

        $response->assertRedirect('/blog');
        $response->assertSessionMissing(LocalePreferenceManager::FRONTEND_SESSION_KEY);
        Event::assertNotDispatched(LocaleChanged::class);
    }

    // ── open-redirect target falls back to the site root ───────────────────────

    public function test_unsafe_redirect_target_falls_back_to_root(): void
    {
        $this->switch(['locale' => 'vi', 'redirect' => 'https://evil.test'])
            ->assertRedirect('/');
    }
}
