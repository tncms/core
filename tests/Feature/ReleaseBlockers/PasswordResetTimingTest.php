<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * CORE-RELEASE-BLOCKERS-R1 (P14-R2): the forgot-password response must not depend on whether the
 * address belongs to an account. The account-dependent work (token hashing and insert, mail transport)
 * therefore runs after the response is produced, and its failures never reach the response.
 */
final class PasswordResetTimingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> token rows seen when the kernel finished handling the request */
    private array $atResponse = [];

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        Event::listen(RequestHandled::class, function (): void {
            $this->atResponse[] = DB::table('password_reset_tokens')->count();
        });
    }

    private function known(): User
    {
        return User::factory()->create(['email' => 'known-reset@example.test']);
    }

    public function test_no_reset_token_exists_when_the_response_is_produced_but_one_exists_afterwards(): void
    {
        Notification::fake();
        $user = $this->known();

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(url('/forgot-password'));

        $this->assertSame([0], $this->atResponse, 'the account-dependent work must not run before the response');
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count(), 'it runs after the response');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_known_and_unknown_addresses_get_byte_identical_responses(): void
    {
        Notification::fake();
        $user = $this->known();

        $known = $this->post('/en/forgot-password', ['email' => $user->email]);
        $this->flushSession();
        $unknown = $this->post('/en/forgot-password', ['email' => 'nobody-reset@example.test']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame(session('status'), __('If an account exists for that email address, a password reset link has been sent.'));
    }

    public function test_a_failing_mail_transport_does_not_turn_a_known_address_into_an_error_response(): void
    {
        // A mail outage used to raise inside the request for a real account only: an existence oracle.
        config(['mail.default' => 'array']);
        $user = $this->known();
        app('events')->listen(\Illuminate\Mail\Events\MessageSending::class, static function (): void {
            throw new \RuntimeException('simulated mail transport outage');
        });
        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (\RuntimeException $e) use (&$reported): bool {
            $reported[] = $e->getMessage();

            return false;
        });

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $this->flushSession();
        $unknown = $this->post('/forgot-password', ['email' => 'nobody-reset@example.test']);

        $known->assertRedirect(url('/forgot-password'));
        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode());
        $this->assertSame($unknown->headers->get('Location'), $known->headers->get('Location'));
        $this->assertContains('simulated mail transport outage', $reported, 'the failure is reported, not swallowed');
    }

    public function test_the_reset_link_keeps_the_request_locale(): void
    {
        Notification::fake();
        $user = $this->known();

        $this->post('/en/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
            return str_contains((string) $n->toMail($user)->actionUrl, '/en/reset-password/');
        });
    }

    public function test_an_invalid_email_is_still_a_validation_error_for_everyone(): void
    {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }
}
