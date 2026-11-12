<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Admin\Pages\MailSettingsPage;
use App\Filament\Admin\Pages\SettingsPage;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\InstallerSession;
use TheNguyen\CMS\Validation\Rules\SafeEmailAddress;

/**
 * CORE-40-INSTALLER-INPUT-CLOSURE-R1 (R-INP-1): a NUL byte inside the local part (`us\0er@example.test`)
 * passes Laravel's `email` / `email:rfc` rule on laravel/framework v12.69.3 + egulias/email-validator 4.0.4,
 * is stored as-is, and later makes Symfony Mime throw while a message to it is built — HTTP 500 on
 * registration with verification enabled, after the user row was already written. Every Core identity /
 * mail email input now also carries {@see SafeEmailAddress}, so the address fails validation with a normal
 * field error instead. No mail leaves the process (array mailer; nothing is sent outside it).
 */
final class EmailControlCharacterInputTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function unsafeAddresses(): array
    {
        return [
            'NUL inside the local part' => ["us\0er@example.test"],
            'NUL inside a quoted local part' => ["\"us\0er\"@example.test"],
            'CR' => ["user@example.test\rX-Injected: 1"],
            'LF' => ["user@example.test\nX-Injected: 1"],
            'CRLF header' => ["user@example.test\r\nBcc: other@example.test"],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validAddresses(): array
    {
        return [
            'plain' => ['plain@example.test'],
            'plus tag' => ['first.last+tag@example.test'],
            'IDN domain' => ['user@bücher.example'],
            'UTF-8 local part' => ['üser@example.test'],
            'literal percent-00 text' => ['user%00@example.test'],
        ];
    }

    #[DataProvider('unsafeAddresses')]
    public function test_the_shared_rule_rejects_control_characters(string $address): void
    {
        $this->assertTrue(Validator::make(['email' => $address], ['email' => ['required', 'string', 'email', new SafeEmailAddress]])->fails());
    }

    #[DataProvider('validAddresses')]
    public function test_the_shared_rule_keeps_valid_addresses(string $address): void
    {
        $this->assertTrue(Validator::make(['email' => $address], ['email' => [new SafeEmailAddress]])->passes());
    }

    public function test_the_shared_rule_reports_a_normal_translatable_message(): void
    {
        $errors = Validator::make(['email' => "us\0er@example.test"], ['email' => [new SafeEmailAddress]])->errors();

        $this->assertSame([__('validation.email', ['attribute' => 'email'])], $errors->get('email'));
    }

    #[DataProvider('unsafeAddresses')]
    public function test_registration_with_verification_rejects_the_address_without_a_500(string $address): void
    {
        config(['cms.auth.registration_enabled' => true, 'cms.auth.email_verification_required' => true]);

        $this->from('/register')->post('/register', [
            'name' => 'Probe', 'email' => $address,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect('/register')->assertSessionHasErrors('email');

        $this->assertSame(0, User::query()->count(), 'No account may be created for an unsafe address.');
    }

    public function test_registration_with_verification_still_works_for_a_valid_address(): void
    {
        Notification::fake();
        config(['cms.auth.registration_enabled' => true, 'cms.auth.email_verification_required' => true]);

        $this->post('/register', [
            'name' => 'Plain', 'email' => 'üser@bücher.example',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'üser@bücher.example']);
    }

    #[DataProvider('unsafeAddresses')]
    public function test_forgot_password_rejects_the_address(string $address): void
    {
        Notification::fake();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $address])
            ->assertRedirect('/forgot-password')->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    #[DataProvider('unsafeAddresses')]
    public function test_password_reset_rejects_the_address(string $address): void
    {
        $this->from('/reset-password/tok')->post('/reset-password', [
            'token' => 'tok', 'email' => $address,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect('/reset-password/tok')->assertSessionHasErrors('email');
    }

    #[DataProvider('unsafeAddresses')]
    public function test_installer_admin_step_rejects_the_address(string $address): void
    {
        // The wizard runs on its own file session (InstallerSession middleware): seed the DB step there.
        $session = app('session')->driver('file');
        $session->start();
        $session->put('install.db', ['host' => '127.0.0.1']);
        $session->save();

        $this->withCookie(InstallerSession::COOKIE, $session->getId())
            ->from('/install/admin')->post('/install/admin', [
                'name' => 'Owner', 'email' => $address,
                'password' => 'password123', 'password_confirmation' => 'password123',
            ])->assertRedirect('/install/admin')->assertSessionHasErrors('email');

        $stored = app('session')->driver('file');
        $stored->setId($session->getId());
        $stored->start();
        $this->assertTrue($stored->has('install.db'), 'Precondition: the request used the seeded wizard session.');
        $this->assertNull($stored->get('install.admin'), 'An unsafe admin address must never reach the install session.');
        $stored->invalidate();
    }

    #[DataProvider('unsafeAddresses')]
    public function test_account_email_change_rejects_the_address(string $address): void
    {
        config(['cms.auth.email_verification_required' => true]);
        $user = User::factory()->create(['email' => 'old@example.test']);

        $this->actingAs($user)->from('/account/security')->post('/account/security/email', [
            'email' => $address, 'current_password' => 'password',
        ])->assertRedirect('/account/security')->assertSessionHasErrors('email');

        $this->assertSame('old@example.test', $user->fresh()->email);
    }

    #[DataProvider('unsafeAddresses')]
    public function test_admin_user_create_rejects_the_address(string $address): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Probe', 'email' => $address, 'password' => 'password123'])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(1, User::query()->count());
    }

    #[DataProvider('unsafeAddresses')]
    public function test_admin_user_edit_rejects_the_address(string $address): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['email' => 'target@example.test']);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['email' => $address])
            ->call('save')
            ->assertHasFormErrors(['email']);

        $this->assertSame('target@example.test', $target->fresh()->email);
    }

    public function test_admin_settings_admin_email_rejects_a_nul_address(): void
    {
        $this->actingAsAdmin();

        Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Test Site')
            ->set('data.writing_default_category_id', null)
            ->set('data.general_admin_email', "ad\0min@example.test")
            ->call('save')
            ->assertHasErrors(['data.general_admin_email']);

        $this->assertStringNotContainsString("\0", (string) settings()->get('general.admin_email', ''));
    }

    public function test_mail_settings_test_recipient_rejects_a_nul_address(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        Livewire::test(MailSettingsPage::class)
            ->callAction('sendTest', data: ['to' => "to\0x@example.test"])
            ->assertHasActionErrors(['to']);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => bcrypt('secret-password')]);
        $this->actingAs($admin);

        return $admin;
    }
}
