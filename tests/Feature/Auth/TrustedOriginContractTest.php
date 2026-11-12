<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Security\TrustedOrigin;
use TheNguyen\CMS\Security\UntrustedOriginException;
use TheNguyen\CMS\Seo\CanonicalHost;

/**
 * CORE-AUTH-TRUSTED-ORIGIN-SECURITY-R1 — the TrustedOrigin contract: strict APP_URL
 * validation, a re-rooted generator that never mutates the shared one, the same
 * origin as the SEO canonical authority for a valid APP_URL, request-independent
 * links in worker/CLI context, and trace-free, value-free configuration errors.
 */
final class TrustedOriginContractTest extends TestCase
{
    use RefreshDatabase;

    private function origin(): TrustedOrigin
    {
        return app(TrustedOrigin::class);
    }

    /** @return array<string, array{0: string, 1: string}> APP_URL => expected root */
    public static function usableOrigins(): array
    {
        return [
            'https' => ['https://www.example.test', 'https://www.example.test'],
            'trailing slash' => ['https://www.example.test/', 'https://www.example.test'],
            'local http' => ['http://localhost', 'http://localhost'],
            'port' => ['http://127.0.0.1:8993', 'http://127.0.0.1:8993'],
            'base path' => ['https://site.test:8443/cms/', 'https://site.test:8443/cms'],
            'upper case' => ['HTTPS://WWW.Example.TEST', 'https://www.example.test'],
            'surrounding whitespace' => ['  https://www.example.test  ', 'https://www.example.test'],
        ];
    }

    #[DataProvider('usableOrigins')]
    public function test_usable_origins(string $appUrl, string $root): void
    {
        config(['app.url' => $appUrl]);

        $this->assertNull($this->origin()->problem());
        $this->assertSame($root, $this->origin()->root());
        $this->assertSame($root.'/reset-password/t?email=a%40b.test', $this->origin()->urls()->route('password.reset', ['token' => 't', 'email' => 'a@b.test']));
    }

    public function test_an_ipv6_literal_origin_is_rejected_rather_than_mailing_a_broken_link(): void
    {
        config(['app.url' => 'http://[::1]:8080']);

        $this->assertSame('APP_URL with an IPv6 literal host is not supported for security links', $this->origin()->problem());
    }

    public function test_the_origin_equals_the_seo_canonical_origin_for_a_valid_app_url(): void
    {
        foreach (['https://www.example.test', 'http://site.test:8080', 'http://localhost'] as $appUrl) {
            config(['app.url' => $appUrl]);
            $this->assertSame(CanonicalHost::configured()['origin'], $this->origin()->root(), $appUrl);
        }
    }

    public function test_an_unusable_origin_throws_without_echoing_the_value(): void
    {
        config(['app.url' => 'https://admin:hunter2@www.example.test']);

        try {
            $this->origin()->urls();
            $this->fail('expected UntrustedOriginException');
        } catch (UntrustedOriginException $e) {
            $this->assertStringContainsString('credentials', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
            $this->assertStringNotContainsString('admin:', $e->getMessage());
        }

        $this->expectException(UntrustedOriginException::class);
        $this->origin()->root();
    }

    public function test_the_shared_url_generator_is_never_mutated(): void
    {
        config(['app.url' => 'https://www.example.test']);
        $shared = app('url');
        $shared->setRequest(Request::create('http://other.test/page'));

        $before = $shared->to('/x');
        $trusted = $this->origin()->urls();

        $this->assertNotSame($shared, $trusted);
        $this->assertSame('https://www.example.test/x', $trusted->to('/x'));
        $this->assertSame('https://www.example.test/reset-password/t', $trusted->route('password.reset', ['token' => 't']));
        $this->assertSame($before, $shared->to('/x'));
        $this->assertSame('http://other.test/x', $shared->to('/x'));
        $this->assertSame('http://other.test/reset-password/t', $shared->route('password.reset', ['token' => 't']));
    }

    public function test_links_built_in_worker_or_cli_context_ignore_the_bound_request(): void
    {
        config(['app.url' => 'https://www.example.test']);
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $user = User::factory()->unverified()->create(['email' => 'worker@example.test']);

        // A long-lived worker may still hold a request from a previous job/HTTP call.
        app('url')->setRequest(Request::create('http://evil.example/'));
        app()->instance('request', Request::create('http://evil.example/'));

        $reset = (new ResetPassword('tok'))->toMail($user)->actionUrl;
        $verify = (new VerifyEmail)->toMail($user)->actionUrl;

        $this->assertStringStartsWith('https://www.example.test/reset-password/tok', $reset);
        $this->assertStringStartsWith('https://www.example.test/email/verify/'.$user->id.'/', $verify);

        // A queued notification carries no origin: the URL is built when it is sent.
        $this->assertStringNotContainsString('evil', serialize(new ResetPassword('tok')));
    }

    public function test_the_guard_logs_the_reason_without_value_token_or_trace(): void
    {
        config(['app.url' => 'https://admin:hunter2@www.example.test', 'mail.default' => 'array']);
        $user = User::factory()->create(['email' => 'log@example.test']);

        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context): bool {
            $dump = $message.json_encode($context);

            return str_contains($message, 'Security links are disabled: APP_URL must not contain credentials')
                && ! str_contains($dump, 'hunter2')
                && ! str_contains($dump, 'secret-token-value')
                && ($context['notification'] ?? null) === ResetPassword::class
                && ! isset($context['exception']);
        });

        $user->sendPasswordResetNotification('secret-token-value');
    }
}
