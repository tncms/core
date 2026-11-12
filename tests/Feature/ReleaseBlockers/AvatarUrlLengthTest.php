<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CORE-RELEASE-BLOCKERS-R1 (TD-P12A-1b): the profile form accepted avatar URLs up to 2048 bytes while
 * `users.avatar` is a 255-character string column, so MySQL refused the write with a 500 ("Data too long").
 * The accepted length must never exceed what the column can store, on every driver.
 */
final class AvatarUrlLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
    }

    private function url(int $length): string
    {
        $prefix = 'https://cdn.example.test/';

        return $prefix.str_repeat('a', $length - strlen($prefix));
    }

    public function test_an_avatar_url_longer_than_the_column_is_a_validation_error_not_a_server_error(): void
    {
        $user = User::factory()->create(['email' => 'avatar-long@example.test', 'avatar' => 'https://cdn.example.test/old.png']);

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/profile', ['name' => 'Avatar Owner', 'avatar' => $this->url(256)])
            ->assertRedirect('/account/profile')
            ->assertSessionHasErrors('avatar');

        $this->assertSame('https://cdn.example.test/old.png', $user->fresh()->avatar, 'a refused update leaves the stored avatar unchanged');
    }

    public function test_an_avatar_url_of_exactly_the_column_length_is_saved(): void
    {
        $user = User::factory()->create(['email' => 'avatar-max@example.test']);
        $url = $this->url(255);

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/profile', ['name' => 'Avatar Owner', 'avatar' => $url])
            ->assertSessionHasNoErrors();

        $this->assertSame($url, $user->fresh()->avatar);
    }

    public function test_the_users_avatar_column_still_holds_255_characters(): void
    {
        // The fix aligns validation with the schema; it does not change the schema.
        $this->assertTrue(Schema::hasColumn('users', 'avatar'));
        $user = User::factory()->create(['email' => 'avatar-col@example.test', 'avatar' => $this->url(255)]);
        $this->assertSame(255, strlen((string) $user->fresh()->avatar));
    }
}
