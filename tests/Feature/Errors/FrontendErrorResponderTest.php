<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;
use TheNguyen\CMS\Http\FrontendErrorResponder;

/**
 * CORE-FRONTEND-1 — FrontendErrorResponder unit behaviour.
 *
 * Exercises status mapping, defer rules (framework isolation), header
 * preservation, debug passthrough, the theme→Core fallback hierarchy and
 * recursion safety, without depending on any real failing route.
 */
class FrontendErrorResponderTest extends TestCase
{
    use RefreshDatabase;

    private function responder(): FrontendErrorResponder
    {
        return app(FrontendErrorResponder::class);
    }

    private function frontendRequest(string $path = '/some-page'): Request
    {
        return Request::create($path, 'GET');
    }

    // === Status mapping (themed for frontend HTML) ===

    /**
     * @return array<string, array{0: \Throwable, 1: int}>
     */
    public static function themedStatusProvider(): array
    {
        return [
            '404 not found' => [new NotFoundHttpException, 404],
            '404 model missing' => [new ModelNotFoundException, 404],
            '403 access denied (http)' => [new AccessDeniedHttpException, 403],
            '403 authorization' => [new AuthorizationException('nope'), 403],
            '419 token mismatch' => [new TokenMismatchException, 419],
            '429 too many requests' => [new TooManyRequestsHttpException(60), 429],
            '500 unexpected' => [new \RuntimeException('boom'), 500],
            '503 unavailable' => [new ServiceUnavailableHttpException, 503],
        ];
    }

    /**
     * @dataProvider themedStatusProvider
     */
    public function test_maps_throwable_to_themed_status(\Throwable $e, int $expected): void
    {
        config(['app.debug' => false]);

        $response = $this->responder()->render($e, $this->frontendRequest());

        $this->assertNotNull($response);
        $this->assertSame($expected, $response->getStatusCode());
    }

    // === Deferral (framework keeps its own contract) ===

    public function test_defers_authentication_exception(): void
    {
        $this->assertNull($this->responder()->render(new AuthenticationException, $this->frontendRequest()));
    }

    public function test_defers_validation_exception(): void
    {
        $e = ValidationException::withMessages(['field' => 'bad']);

        $this->assertNull($this->responder()->render($e, $this->frontendRequest()));
    }

    public function test_defers_json_request(): void
    {
        $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

        $this->assertNull($this->responder()->render(new NotFoundHttpException, $request));
    }

    public function test_defers_admin_path(): void
    {
        $this->assertNull($this->responder()->render(new NotFoundHttpException, Request::create('/admin/x', 'GET')));
    }

    public function test_defers_install_and_upgrade_and_livewire_and_api(): void
    {
        foreach (['/install/x', '/upgrade/x', '/livewire/message/y', '/api/v1/z'] as $path) {
            $this->assertNull(
                $this->responder()->render(new NotFoundHttpException, Request::create($path, 'GET')),
                "path {$path} should defer",
            );
        }
    }

    public function test_defers_server_error_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        // 500 in debug → framework diagnostics; 4xx is still themed.
        $this->assertNull($this->responder()->render(new \RuntimeException('boom'), $this->frontendRequest()));
        $this->assertNotNull($this->responder()->render(new NotFoundHttpException, $this->frontendRequest()));
    }

    // === Header preservation ===

    public function test_preserves_retry_after_header_on_429(): void
    {
        config(['app.debug' => false]);

        $response = $this->responder()->render(new TooManyRequestsHttpException(120), $this->frontendRequest());

        $this->assertNotNull($response);
        $this->assertSame('120', $response->headers->get('Retry-After'));
    }

    // === Hierarchy + recursion safety ===

    public function test_broken_theme_error_views_fall_back_to_core_safe_view(): void
    {
        config(['app.debug' => false]);

        // Force both theme error tiers to throw on render.
        View::replaceNamespace('theme', [base_path('tests/Fixtures/frontend-errors')]);
        $this->assertTrue(View::exists('theme::errors.404'));
        $this->assertTrue(View::exists('theme::errors.error'));

        $response = $this->responder()->render(new NotFoundHttpException, $this->frontendRequest());

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        // Core self-contained fallback markers (locale-independent) — never the
        // theme layout, never a leak of the theme render failure.
        $content = (string) $response->getContent();
        $this->assertStringContainsString('noindex,follow', $content);
        $this->assertStringContainsString('class="status"', $content);
        $this->assertStringContainsString(route('cms.home'), $content);
        $this->assertStringNotContainsString('render boom', $content);
    }

    public function test_uses_theme_generic_tier_when_specialized_absent(): void
    {
        config(['app.debug' => false]);

        // Only a generic errors.error exists (no errors.404) in this theme.
        View::replaceNamespace('theme', [base_path('tests/Fixtures/frontend-errors-generic-only')]);
        $this->assertFalse(View::exists('theme::errors.404'));
        $this->assertTrue(View::exists('theme::errors.error'));

        $response = $this->responder()->render(new NotFoundHttpException, $this->frontendRequest());

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('generic-fixture-error', (string) $response->getContent());
    }

    public function test_core_fallback_is_self_contained_and_escaped(): void
    {
        $response = $this->responder()->render(new NotFoundHttpException, $this->frontendRequest());
        // Default theme ships error views, so first render themed; force Core:
        View::replaceNamespace('theme', [base_path('tests/Fixtures/frontend-errors')]);
        $response = $this->responder()->render(new NotFoundHttpException, $this->frontendRequest());

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('name="robots"', $content);
        $this->assertStringContainsString('name="q"', $content);
    }

    // === EN/VI dictionary parity for the error strings ===

    public function test_error_strings_have_vietnamese_translations(): void
    {
        $this->assertSame('Không tìm thấy trang', core_trans('Page not found', [], 'vi'));
        $this->assertSame('Truy cập bị từ chối', core_trans('Access denied', [], 'vi'));
        $this->assertSame('Về trang chủ', core_trans('Go to homepage', [], 'vi'));
        $this->assertSame('Page not found', core_trans('Page not found', [], 'en'));
    }
}
