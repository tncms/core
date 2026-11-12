<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CORE-AUTH-TRUSTED-ORIGIN-SECURITY-R1 — security links never take their origin
 * from the request.
 *
 * Password-reset and email-verification links carry a capability (a reset token,
 * a signature). They are built from the configured APP_URL origin only: a forged
 * Host, forwarded headers (trusted or not) and the SEO `CMS_CANONICAL_HOST=request`
 * policy cannot change it. An unusable APP_URL fails closed: no link is mailed and
 * the public response is unchanged, so the form still reveals no account.
 *
 * The real notification pipeline runs (array mailer, no Notification::fake), so
 * the assertions cover the rendered email and Core's send-time guard.
 */
final class SecurityLinkTrustedOriginTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://www.example.test';

    private int $seq = 0;

    /** @var list<NotificationSent> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        // mailedLink() re-renders the notification to obtain the expected link; a signed verification link
        // embeds `expires` = now()+N, so a send/re-render pair straddling a second boundary differed by one
        // second (intermittent failure). Freeze the clock; travel() still moves it for the expiry test.
        $this->freezeTime();
        config(['app.url' => self::SITE, 'mail.default' => 'array']);
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            $this->sent[] = $event;
        });
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    /**
     * POST the forgot-password form at $url and return the mailed reset link (or
     * null when nothing was sent). Each call uses a fresh account and client IP so
     * the broker and route throttles never interfere.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $server
     */
    private function resetLink(string $url, array $headers = [], array $server = []): ?string
    {
        $user = User::factory()->create(['email' => 'reset-'.(++$this->seq).'@example.test']);
        $server += ['REMOTE_ADDR' => '10.9.0.'.$this->seq];

        $response = $this->withHeaders($headers)->withServerVariables($server)->post($url, ['email' => $user->email]);
        $response->assertStatus(302)->assertSessionHasNoErrors();

        return $this->mailedLink($user, ResetPassword::class);
    }

    /** The action URL of the last $class mail sent to $user, checked against the rendered body. */
    private function mailedLink(User $user, string $class): ?string
    {
        $link = null;
        foreach ($this->sent as $event) {
            if ($event->notification instanceof $class && $event->notifiable->is($user)) {
                $link = $event->notification->toMail($user)->actionUrl;
            }
        }

        if ($link !== null) {
            $bodies = '';
            foreach (app('mail.manager')->mailer('array')->getSymfonyTransport()->messages() as $message) {
                $bodies .= (string) $message->getOriginalMessage()->getHtmlBody()."\n";
            }
            $this->assertStringContainsString(htmlspecialchars($link, ENT_QUOTES), $bodies, 'the mailed body carries the same link');
            $this->assertStringNotContainsString('evil.example', $bodies);
        }

        return $link;
    }

    private function assertNothingMailed(): void
    {
        $this->assertSame([], $this->sent, 'no security notification was sent');
        $this->assertCount(0, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    private function origin(?string $url): string
    {
        $this->assertNotNull($url, 'a link was mailed');
        $parts = parse_url((string) $url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    // ---- host poisoning

    public function test_the_configured_host_gets_a_configured_origin_link(): void
    {
        $link = $this->resetLink(self::SITE.'/forgot-password');

        $this->assertStringStartsWith(self::SITE.'/reset-password/', (string) $link);
    }

    public function test_a_forged_host_never_reaches_the_reset_link(): void
    {
        foreach (['http://evil.example', 'https://evil.example', 'http://evil.example:8443', 'http://www.example.test:9999', 'http://127.0.0.1'] as $host) {
            $link = $this->resetLink($host.'/forgot-password');

            $this->assertSame(self::SITE, $this->origin($link), $host);
            $this->assertStringNotContainsString('evil', (string) $link, $host);
        }
    }

    public function test_untrusted_forwarded_headers_never_reach_the_reset_link(): void
    {
        config(['trustedproxy.proxies' => '10.20.30.40']); // the client is not this proxy

        $cases = [
            ['X-Forwarded-Host' => 'evil.example'],
            ['X-Forwarded-Proto' => 'http'],
            ['X-Forwarded-Port' => '8443'],
            ['Forwarded' => 'for=1.2.3.4;host=evil.example;proto=http'],
            ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '81', 'Forwarded' => 'host=evil.example'],
        ];

        foreach ($cases as $headers) {
            $link = $this->resetLink('http://evil.example/forgot-password', $headers);
            $this->assertSame(self::SITE, $this->origin($link), (string) json_encode($headers));
        }
    }

    public function test_a_trusted_proxy_cannot_replace_the_configured_origin(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $proxy = ['REMOTE_ADDR' => '127.0.0.1'];

        $foreign = $this->resetLink('http://10.0.0.5:8080/forgot-password',
            ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '80'], $proxy);
        $this->assertSame(self::SITE, $this->origin($foreign));

        $legit = $this->resetLink('http://10.0.0.5:8080/forgot-password',
            ['X-Forwarded-Host' => 'www.example.test', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'], $proxy);
        $this->assertStringStartsWith(self::SITE.'/reset-password/', (string) $legit);
    }

    public function test_the_seo_request_policy_never_weakens_security_links(): void
    {
        foreach (['request', 'configured'] as $policy) {
            config(['cms.deployment.canonical_host' => $policy]);
            $this->assertSame(self::SITE, $this->origin($this->resetLink('http://evil.example/forgot-password')), $policy);
        }
    }

    public function test_the_reset_link_keeps_locale_token_and_recipient(): void
    {
        $en = (string) $this->resetLink('http://evil.example/en/forgot-password');
        $this->assertStringStartsWith(self::SITE.'/en/reset-password/', $en);

        $vi = (string) $this->resetLink('http://evil.example/forgot-password');
        $this->assertMatchesRegularExpression('~^'.preg_quote(self::SITE, '~').'/reset-password/[A-Za-z0-9]{64}\?email=reset-\d+%40example\.test$~', $vi);
    }

    public function test_port_and_base_path_of_the_configured_origin_are_kept(): void
    {
        config(['app.url' => 'https://site.test:8443/cms/']);

        $this->assertStringStartsWith('https://site.test:8443/cms/reset-password/', (string) $this->resetLink('http://evil.example/forgot-password'));
    }

    // ---- verification links (signed)

    public function test_the_verification_link_uses_the_configured_origin_and_verifies_there(): void
    {
        config(['cms.auth.email_verification_required' => true]);
        $user = User::factory()->unverified()->create(['email' => 'verify@example.test']);

        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.9.9.1'])
            ->post('http://evil.example/email/verification-notification')->assertStatus(302);

        $link = (string) $this->mailedLink($user, VerifyEmail::class);
        $this->assertSame(self::SITE, $this->origin($link));
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $link);

        // The signature covers the configured origin: valid there, rejected on a
        // forged host and when tampered with.
        $this->get(str_replace(self::SITE, 'http://evil.example', $link))->assertStatus(403);
        $this->get($link.'x')->assertStatus(403);
        $this->get($link)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_verification_link_is_rejected(): void
    {
        config(['cms.auth.email_verification_required' => true]);
        $user = User::factory()->unverified()->create(['email' => 'verify-exp@example.test']);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.9.9.2'])
            ->post('http://evil.example/email/verification-notification');

        $link = $this->mailedLink($user, VerifyEmail::class);
        $this->assertNotNull($link);

        $this->travel(61)->minutes();
        $this->get((string) $link)->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    // ---- fail closed on an unusable APP_URL

    /** @return array<string, array{0: mixed}> */
    public static function unusableOrigins(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'not a url' => ['not a url'],
            'no host' => ['https:///path'],
            'unsupported scheme' => ['ftp://www.example.test'],
            'credentials' => ['https://user:secret@www.example.test'],
            'query' => ['https://www.example.test/?next=1'],
            'fragment' => ['https://www.example.test/#top'],
            'bad host' => ['https://exa mple.test'],
            'dot segment' => ['https://www.example.test/../admin'],
            'array' => [['https://www.example.test']],
        ];
    }

    #[DataProvider('unusableOrigins')]
    public function test_an_unusable_app_url_fails_closed_without_revealing_the_account(mixed $appUrl): void
    {
        config(['app.url' => $appUrl]);
        $known = User::factory()->create(['email' => 'known@example.test']);

        $hit = $this->withServerVariables(['REMOTE_ADDR' => '10.8.0.1'])->post('http://evil.example/forgot-password', ['email' => $known->email]);
        $miss = $this->withServerVariables(['REMOTE_ADDR' => '10.8.0.2'])->post('http://evil.example/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertNothingMailed();
        $this->assertSame($miss->getStatusCode(), $hit->getStatusCode());
        $this->assertSame($miss->getSession()->get('status'), $hit->getSession()->get('status'));
        $hit->assertSessionHasNoErrors();
    }

    public function test_an_unusable_app_url_never_mails_a_verification_link(): void
    {
        config(['cms.auth.email_verification_required' => true, 'app.url' => 'https://user:secret@www.example.test']);
        $user = User::factory()->unverified()->create(['email' => 'verify-bad@example.test']);

        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.9.9.3'])
            ->post('http://evil.example/email/verification-notification')->assertStatus(302);

        $this->assertNothingMailed();
    }

    public function test_a_direct_notification_send_also_fails_closed(): void
    {
        config(['app.url' => '']);
        $user = User::factory()->create(['email' => 'direct@example.test']);

        // Even outside Core's controllers (e.g. an extension calling the model),
        // a security mail with an untrusted origin is never sent.
        $user->sendPasswordResetNotification(Str::random(64));
        $user->sendEmailVerificationNotification();

        $this->assertNothingMailed();
    }
}
