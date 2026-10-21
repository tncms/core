<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Profile\UserProfileManager;

/**
 * P1-B Gate 2 — typed value engine. Reads/writes go through UserProfileManager
 * (binding `cms.user_profile`). Only implemented types are exercised; values
 * are normalized and declaratively validated; empty normalizes to null and
 * stays distinct from 0 / false; locale '' is the neutral sentinel.
 */
class UserProfileManagerTest extends TestCase
{
    use RefreshDatabase;

    private function mgr(): UserProfileManager
    {
        return app('cms.user_profile');
    }

    public function test_owner_sets_and_reads_a_text_value(): void
    {
        $u = $this->user();
        $this->field('t.headline', 'text');

        $this->mgr()->set($u, ['t.headline' => '  Hello world  '], $u);

        $this->assertSame('Hello world', $this->mgr()->get($u, 't.headline', $u)); // text trims
    }

    public function test_unset_field_returns_its_default(): void
    {
        $u = $this->user();
        $this->field('t.role_label', 'text', default: 'Member');

        $this->assertSame('Member', $this->mgr()->get($u, 't.role_label', $u));
    }

    public function test_number_preserves_int_vs_float_and_enforces_range(): void
    {
        $u = $this->user();
        $this->field('t.age', 'number', opts: ['min' => 0, 'max' => 120]);

        $this->mgr()->set($u, ['t.age' => '42'], $u);
        $this->assertSame(42, $this->mgr()->get($u, 't.age', $u));

        $this->mgr()->set($u, ['t.age' => 3.5], $u);
        $this->assertSame(3.5, $this->mgr()->get($u, 't.age', $u));

        $this->expectException(ValidationException::class);
        $this->mgr()->set($u, ['t.age' => 999], $u);
    }

    public function test_boolean_zero_and_null_are_distinct(): void
    {
        $u = $this->user();
        $this->field('t.subscribed', 'boolean');
        $this->field('t.count', 'number');

        $this->mgr()->set($u, ['t.subscribed' => false, 't.count' => 0], $u);
        $this->assertFalse($this->mgr()->get($u, 't.subscribed', $u));
        $this->assertSame(0, $this->mgr()->get($u, 't.count', $u));

        // Empty string clears to null (an explicit unset), not false/0.
        $this->mgr()->set($u, ['t.subscribed' => ''], $u);
        $this->assertNull($this->mgr()->get($u, 't.subscribed', $u));
    }

    public function test_select_rejects_values_outside_choices(): void
    {
        $u = $this->user();
        $this->field('t.plan', 'select', opts: ['choices' => ['free', 'pro']]);

        $this->mgr()->set($u, ['t.plan' => 'pro'], $u);
        $this->assertSame('pro', $this->mgr()->get($u, 't.plan', $u));

        $this->expectException(ValidationException::class);
        $this->mgr()->set($u, ['t.plan' => 'enterprise'], $u);
    }

    public function test_checkbox_stores_subset_of_choices(): void
    {
        $u = $this->user();
        $this->field('t.tags', 'checkbox', opts: ['choices' => ['a', 'b', 'c']]);

        $this->mgr()->set($u, ['t.tags' => ['a', 'c', 'a']], $u);
        $this->assertSame(['a', 'c'], $this->mgr()->get($u, 't.tags', $u));
    }

    public function test_email_and_url_are_validated(): void
    {
        $u = $this->user();
        $this->field('t.site', 'url');
        $this->mgr()->set($u, ['t.site' => 'https://example.test/path'], $u);
        $this->assertSame('https://example.test/path', $this->mgr()->get($u, 't.site', $u));

        $this->field('t.mail', 'email');
        $this->expectException(ValidationException::class);
        $this->mgr()->set($u, ['t.mail' => 'not-an-email'], $u);
    }

    public function test_locale_slots_are_independent_with_neutral_fallback(): void
    {
        $u = $this->user();
        $this->field('t.headline', 'text');

        $this->mgr()->set($u, ['t.headline' => 'Neutral'], $u);          // locale ''
        $this->mgr()->set($u, ['t.headline' => 'Xin chào'], $u, 'vi');   // locale 'vi'

        $this->assertSame('Xin chào', $this->mgr()->get($u, 't.headline', $u, 'vi'));
        $this->assertSame('Neutral', $this->mgr()->get($u, 't.headline', $u, 'en')); // falls back to neutral
        $this->assertSame('Neutral', $this->mgr()->get($u, 't.headline', $u));
    }

    public function test_forget_clears_a_value(): void
    {
        $u = $this->user();
        $this->field('t.bio', 'textarea');
        $this->mgr()->set($u, ['t.bio' => 'x'], $u);
        $this->mgr()->forget($u, 't.bio', $u);
        $this->assertNull($this->mgr()->get($u, 't.bio', $u));
    }

    public function test_validate_is_a_dry_run(): void
    {
        $u = $this->user();
        $this->field('t.bio', 'text'); // text trims; textarea preserves whitespace
        $out = $this->mgr()->validate($u, ['t.bio' => ' hi '], $u);
        $this->assertSame(['t.bio' => 'hi'], $out);
        $this->assertNull($this->mgr()->get($u, 't.bio', $u)); // nothing persisted
    }

    public function test_inactive_field_is_invisible_to_the_engine(): void
    {
        $u = $this->user();
        $f = $this->field('t.legacy', 'text');
        $this->mgr()->set($u, ['t.legacy' => 'v'], $u);
        $f->update(['is_active' => false]);

        $this->assertNull($this->mgr()->get($u, 't.legacy', $u)); // hidden
    }

    private function user(): User
    {
        return User::query()->forceCreate([
            'name' => 'U', 'email' => 'u-'.uniqid().'@example.test', 'password' => bcrypt('secret-password'),
        ]);
    }

    private function field(string $key, string $type = 'text', string $vis = 'owner', string $edit = 'owner', array $opts = [], mixed $default = null, bool $enc = false): UserProfileField
    {
        $group = UserProfileFieldGroup::query()->firstOrCreate(['key' => 't'], ['source' => 'core']);

        return UserProfileField::query()->create([
            'key' => $key, 'group_id' => $group->id, 'type' => $type,
            'visibility' => $vis, 'editability' => $edit, 'options' => $opts,
            'default_value' => $default, 'is_encrypted' => $enc, 'source' => 'core', 'is_active' => true,
        ]);
    }
}
