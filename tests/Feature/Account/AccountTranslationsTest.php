<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — every translatable string of Core's account and
 * auth surface exists in lang/en.json and lang/vi.json (automated, so a new string
 * without translations fails here), and pages declare the rendered language.
 */
final class AccountTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCES = [
        'packages/thenguyen/cms-core/resources/views/auth/*.blade.php',
        'packages/thenguyen/cms-core/resources/views/account/*.blade.php',
        'packages/thenguyen/cms-core/resources/views/account/partials/*.blade.php',
        'packages/thenguyen/cms-core/resources/views/layouts/auth.blade.php',
        'packages/thenguyen/cms-core/src/Http/Controllers/Auth/*.php',
        'packages/thenguyen/cms-core/src/Http/Controllers/Account/*.php',
        'packages/thenguyen/cms-core/src/Services/AccountManager.php',
        'packages/thenguyen/cms-core/src/Profile/AccountProfileFieldsPresenter.php',
    ];

    /** @return array<string, list<string>> key => files */
    private function keys(): array
    {
        $keys = [];
        foreach (self::SOURCES as $glob) {
            foreach (glob(base_path($glob)) ?: [] as $file) {
                preg_match_all("/__\\(\\s*'((?:\\\\'|[^'])*)'/", (string) file_get_contents($file), $m);
                foreach ($m[1] as $key) {
                    $keys[str_replace("\\'", "'", $key)][] = basename($file);
                }
            }
        }

        return $keys;
    }

    public function test_every_account_and_auth_string_has_english_and_vietnamese(): void
    {
        $keys = $this->keys();
        $this->assertGreaterThan(80, count($keys), 'extraction sanity');

        foreach (['en', 'vi'] as $locale) {
            $dictionary = json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
            $missing = array_diff(array_keys($keys), array_keys($dictionary));
            $this->assertSame([], array_values($missing), "lang/{$locale}.json is missing account/auth strings");
        }
    }

    public function test_vietnamese_password_broker_messages_exist(): void
    {
        app()->setLocale('vi');
        foreach (['reset', 'sent', 'throttled', 'token', 'user'] as $status) {
            $this->assertNotSame("passwords.{$status}", __("passwords.{$status}"), $status);
        }
    }

    public function test_html_lang_and_chrome_follow_the_rendered_locale(): void
    {
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->actingAs(User::factory()->create());

        $this->get('/account/security')->assertSee('<html lang="vi">', false)
            ->assertSee('Đổi mật khẩu')->assertSee('Điều hướng tài khoản')->assertSee('Đăng xuất')
            ->assertDontSee('>Change password<', false);
        $this->get('/en/account/security')->assertSee('<html lang="en">', false)
            ->assertSee('Change password')->assertSee('Sign out')
            ->assertDontSee('Đổi mật khẩu');
    }
}
