<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * CORE-40-DEPENDENCY-SECURITY-R1 (A-ADV-1): an address carrying CR/LF must never pass
 * Core's unauthenticated e-mail validation or reach the mail layer.
 *
 * On laravel/framework v12.43.1 + symfony/mime v7.4.0 a quoted local part with CRLF
 * (`"a\r\nb"@host`) passed the default `email` rule and Symfony Mime accepted it as an
 * Address (GHSA-5vg9-5847-vvmq, CVE-2026-45067). The patched lock rejects it at both layers.
 * No mail leaves the process: the notification channel is faked and the address checks are local.
 */
final class EmailHeaderInjectionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function hostileAddresses(): array
    {
        return [
            'quoted local part with CRLF' => ["\"a\r\nb\"@example.test"],
            'quoted local part with LF' => ["\"a\nBcc: x@example.test\"@example.test"],
            'CRLF header after the address' => ["user@example.test\r\nBcc: other@example.test"],
            'bare LF' => ["user@example.test\nX-Injected: 1"],
            'bare CR' => ["user@example.test\rX-Injected: 1"],
        ];
    }

    #[DataProvider('hostileAddresses')]
    public function test_default_email_rule_rejects_line_breaks(string $address): void
    {
        $this->assertTrue(
            Validator::make(['email' => $address], ['email' => ['required', 'string', 'email']])->fails(),
            'The default email rule must reject an address containing CR or LF.',
        );
    }

    #[DataProvider('hostileAddresses')]
    public function test_symfony_mime_refuses_line_breaks(string $address): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Address($address);
    }

    #[DataProvider('hostileAddresses')]
    public function test_registration_rejects_hostile_address_and_sends_nothing(string $address): void
    {
        Notification::fake();
        config(['cms.auth.registration_enabled' => true]);

        $this->from('/register')->post('/register', [
            'name' => 'Probe', 'email' => $address,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, User::query()->count());
        Notification::assertNothingSent();
    }

    #[DataProvider('hostileAddresses')]
    public function test_forgot_password_rejects_hostile_address_and_sends_nothing(string $address): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'a@example.test']);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $address])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_plain_address_still_registers(): void
    {
        Notification::fake();
        config(['cms.auth.registration_enabled' => true]);

        $this->post('/register', [
            'name' => 'Plain', 'email' => 'plain@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'plain@example.test']);
    }
}
