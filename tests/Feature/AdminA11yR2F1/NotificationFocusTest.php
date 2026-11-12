<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR2F1;

use App\Models\User;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-H-Z1-R2-F1-CLOSURE-R1 — R2-F1 (WCAG 2.4.11): the notification stack moves away from the focused element while
 * it overlaps it. The browser probes in the phase evidence are the behavioural proof; this pins the shipped markup,
 * the script's limits, and the two Filament facts the fix depends on (so a Filament upgrade that changes them fails here).
 */
final class NotificationFocusTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'data-tn-admin-notification-focus';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
    }

    private function signIn(): void
    {
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    private static function block(string $html, string $tag): string
    {
        preg_match('/<'.$tag.'[^>]*\b'.self::MARKER.'\b[^>]*>(.*?)<\/'.$tag.'>/s', $html, $m);

        return $m[1] ?? '';
    }

    public function test_style_and_script_are_emitted_once_on_admin_pages_and_on_the_sign_in_page(): void
    {
        $this->signIn();
        $html = (string) $this->get('/admin/tags/create')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, self::MARKER), 'one <style> and one <script>');

        auth()->logout();
        $guest = (string) $this->get('/admin/login')->assertOk()->getContent();
        $this->assertSame(2, substr_count($guest, self::MARKER), 'sign-in notifications are covered too');
    }

    public function test_script_only_moves_the_stack_and_stays_bounded(): void
    {
        $this->signIn();
        $js = self::block((string) $this->get('/admin/tags/create')->getContent(), 'script');
        $this->assertNotSame('', $js);

        $this->assertStringContainsString('if (window.__tnAdminNotificationFocus) {', $js, 'idempotent: one install per page');
        $this->assertSame(1, substr_count($js, 'setAttribute('), 'one DOM write');
        $this->assertStringContainsString("root.setAttribute('data-tn-no-edge', edge)", $js, 'the only write is the edge attribute on <html>');
        $this->assertSame(1, substr_count($js, 'removeAttribute('), 'and its removal');
        $this->assertStringContainsString("root.removeAttribute('data-tn-no-edge')", $js, 'Filament placement is restored once no notification is on screen');
        $this->assertStringContainsString(".visibility !== 'hidden'", $js, 'a closed notification no longer counts');
        $this->assertStringContainsString('observer.observe(stack, { childList: true })', $js, 'observes the notification stack only, not the page');
        $this->assertStringContainsString("active.closest('.fi-no')", $js, 'focus inside a notification is left alone');
        $this->assertStringContainsString('if (! overlaps) {', $js, 'nothing moves unless a notification overlaps the focused element');
        $this->assertStringContainsString("? 'bottom' : 'top'", $js, 'the edge follows the element position only');
        foreach (['innerHTML', 'setInterval', 'setTimeout', 'fetch(', 'localStorage', 'sessionStorage', '.focus(', 'preventDefault', 'stopPropagation', 'keydown'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, $forbidden);
        }
        $this->assertStringContainsString("window.addEventListener('scroll', schedule, { passive: true, capture: true })", $js);
        $this->assertStringContainsString("window.addEventListener('resize', schedule, { passive: true })", $js);
        $this->assertStringContainsString("window.addEventListener('blur', schedule)", $js, 'focus into the editor iframe');
        $this->assertStringContainsString("document.addEventListener('livewire:navigated', start)", $js, 'page swapped in after a save');
        $this->assertStringContainsString('Math.min(box.bottom, window.innerHeight)', $js, 'only the in-viewport part counts');
    }

    public function test_style_reuses_filaments_own_vertical_alignment_values(): void
    {
        $this->signIn();
        $css = self::block((string) $this->get('/admin/tags/create')->getContent(), 'style');
        $this->assertStringContainsString('html[data-tn-no-edge="bottom"] .fi-no.fi-vertical-align-start { flex-direction: column; justify-content: flex-end; }', $css);
        $this->assertStringContainsString('html[data-tn-no-edge="top"] .fi-no.fi-vertical-align-end { flex-direction: column-reverse; justify-content: flex-end; }', $css);
    }

    public function test_panel_keeps_filaments_configured_notification_alignment(): void
    {
        $this->assertSame(Alignment::Right, Notifications::$alignment);
        $this->assertSame(VerticalAlignment::Start, Notifications::$verticalAlignment);
    }

    public function test_filament_contract_the_fix_depends_on_is_unchanged(): void
    {
        $view = (string) file_get_contents(base_path('vendor/filament/notifications/resources/views/notifications.blade.php'));
        $this->assertStringContainsString("'fi-no',", $view);
        $this->assertStringContainsString("'fi-vertical-align-' . static::\$verticalAlignment->value,", $view);
        $this->assertStringContainsString('role="status"', $view);

        $css = (string) file_get_contents(base_path('vendor/filament/notifications/resources/css/notifications.css'));
        $this->assertMatchesRegularExpression('/&\.fi-vertical-align-start \{\s*@apply flex-col-reverse justify-end;\s*\}/', $css);
        $this->assertMatchesRegularExpression('/&\.fi-vertical-align-end \{\s*@apply flex-col justify-end;\s*\}/', $css);
        $this->assertStringContainsString('@apply pointer-events-none fixed inset-4 z-50 mx-auto flex gap-3;', $css);
    }
}
