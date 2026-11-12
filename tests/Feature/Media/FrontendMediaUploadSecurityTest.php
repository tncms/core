<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Media\Frontend\FrontendMediaException;
use TheNguyen\CMS\Models\Media;

/**
 * CORE-40-FRONTEND-MEDIA-R1 Gate T3 — upload security of the frontend media API.
 */
final class FrontendMediaUploadSecurityTest extends TestCase
{
    use InteractsWithFrontendMedia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontendMedia();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontendMedia();
        parent::tearDown();
    }

    private function upload($file, ?\App\Models\User $user = null)
    {
        $user ??= $this->userWithRole('author');

        return $this->actingAs($user)->post('/account/media', ['file' => $file], ['Accept' => 'application/json']);
    }

    /** @return array<string, array{string, string}> */
    public static function validImages(): array
    {
        return ['jpg' => ['photo.jpg', 'jpeg'], 'jpeg' => ['photo.jpeg', 'jpeg'], 'png' => ['photo.png', 'png'], 'webp' => ['photo.webp', 'webp'], 'gif' => ['photo.gif', 'gif']];
    }

    #[DataProvider('validImages')]
    public function test_valid_images_are_accepted(string $name, string $format): void
    {
        $response = $this->upload($this->realImage($name, $format, 40, 30))->assertCreated();

        $response->assertJsonPath('data.width', 40)->assertJsonPath('data.height', 30);
        $this->assertSame(['id', 'url', 'width', 'height', 'mime_type', 'title', 'alt', 'created_at'], array_keys($response->json('data')));
        $this->assertCount(1, $this->storedUploadFiles());
    }

    /** @return array<string, array{\Closure, string}> */
    public static function rejectedUploads(): array
    {
        return [
            'php bytes renamed to jpg' => [fn (self $t) => $t->realFile('shell.jpg', "<?php system(\$_GET['c']); ?>\n", 'image/jpeg'), 'media_invalid'],
            'php extension' => [fn (self $t) => $t->realFile('shell.php', "<?php echo 1;\n", 'image/jpeg'), 'media_invalid'],
            'double extension' => [fn (self $t) => $t->realFile('image.php.jpg', "<?php echo 1;\n", 'image/jpeg'), 'media_invalid'],
            'html as png' => [fn (self $t) => $t->realFile('x.png', '<html><script>alert(1)</script></html>', 'image/png'), 'media_invalid'],
            'svg' => [fn (self $t) => $t->realFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'), 'media_invalid'],
            'pdf is not an image' => [fn (self $t) => $t->realFile('doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'application/pdf'), 'media_invalid'],
            'png bytes with jpg extension' => [fn (self $t) => $t->realImage('mismatch.jpg', 'png'), 'media_invalid'],
            'no extension' => [fn (self $t) => $t->realImage('noext', 'jpeg'), 'media_invalid'],
            'empty file' => [fn (self $t) => $t->realFile('empty.jpg', ''), 'media_invalid'],
            'dimension bomb' => [fn (self $t) => $t->craftedPng('bomb.png', 30000, 30000), 'media_invalid'],
            'one huge side' => [fn (self $t) => $t->craftedPng('wide.png', 6001, 10), 'media_invalid'],
            'header-only png' => [fn (self $t) => $t->craftedPng('tiny.png', 0, 0), 'media_invalid'],
        ];
    }

    #[DataProvider('rejectedUploads')]
    public function test_dangerous_or_unsupported_uploads_are_rejected_without_side_effects(\Closure $make, string $code): void
    {
        $this->upload($make($this))->assertStatus(422)->assertJsonPath('error.code', $code);

        $this->assertSame(0, Media::query()->withTrashed()->count());
        $this->assertSame([], $this->storedUploadFiles());
    }

    public function test_a_missing_file_field_is_a_422(): void
    {
        $this->actingAs($this->userWithRole('author'))->post('/account/media', [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'media_invalid');
    }

    public function test_dimension_ceiling_is_configurable(): void
    {
        settings()->set('media.frontend_max_dimension', 100, 'integer');

        $this->upload($this->realImage('ok.png', 'png', 100, 50))->assertCreated();
        $this->upload($this->realImage('big.png', 'png', 101, 50))->assertStatus(422)->assertJsonPath('error.code', 'media_invalid');
    }

    public function test_size_limit_of_media_manager_is_preserved(): void
    {
        settings()->set('media.max_upload_size_mb', 1, 'integer');
        $bytes = random_bytes(1024 * 1024 + 10);
        $file = $this->realImage('noise.png', 'png', 16, 16);
        // Append noise after a valid PNG: still sniffs as PNG, exceeds 1 MB.
        file_put_contents($file->getRealPath(), $bytes, FILE_APPEND);
        clearstatcache();
        $file = new \Illuminate\Http\UploadedFile($file->getRealPath(), 'noise.png', null, null, true);

        $this->upload($file)->assertStatus(422)->assertJsonPath('error.code', 'media_invalid');
        $this->assertSame([], $this->storedUploadFiles());
    }

    public function test_quota_is_enforced_per_user_and_counts_active_media_only(): void
    {
        settings()->set('media.frontend_quota_mb', 1, 'integer');
        $author = $this->userWithRole('author');
        $other = $this->userWithRole('author');
        $big = $this->mediaRow($author, ['size' => 1024 * 1024 - 100]);
        $this->mediaRow($other, ['size' => 50 * 1024 * 1024]); // other users never count

        $this->upload($this->realImage('a.jpg'), $author)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'media_quota_exceeded')
            ->assertJsonPath('error.quota.limit_bytes', 1024 * 1024);
        $this->assertSame([], $this->storedUploadFiles());

        $big->delete(); // soft-deleted media no longer counts
        $this->upload($this->realImage('b.jpg'), $author)->assertCreated();
        $this->upload($this->realImage('c.jpg'), $other)->assertStatus(422)->assertJsonPath('error.code', 'media_quota_exceeded');
    }

    public function test_quota_usage_is_reported_by_the_service(): void
    {
        $author = $this->userWithRole('author');
        $this->mediaRow($author, ['size' => 1234]);

        $usage = app('cms.media.frontend')->quotaUsage($author);

        $this->assertSame(1234, $usage['used_bytes']);
        $this->assertSame(100 * 1024 * 1024, $usage['limit_bytes']);
    }

    public function test_upload_requests_are_throttled_per_user(): void
    {
        settings()->set('media.frontend_uploads_per_minute', 2, 'integer');
        $author = $this->userWithRole('author');

        $this->upload($this->realImage('1.jpg'), $author)->assertCreated();
        $this->upload($this->realImage('2.jpg'), $author)->assertCreated();
        $this->upload($this->realImage('3.jpg'), $author)->assertStatus(429)->assertJsonPath('error.code', 'media_rate_limited');
        $this->assertCount(2, $this->storedUploadFiles());

        // A different user has their own budget.
        $this->upload($this->realImage('4.jpg'), $this->userWithRole('author'))->assertCreated();
    }

    public function test_routes_run_in_the_web_group_so_csrf_applies(): void
    {
        $route = Route::getRoutes()->getByName('cms.account.media.store');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('web', $middleware);
        $this->assertContains('cms.auth', $middleware);
        $this->assertContains('cms.frontend_session', $middleware);
        $web = app('router')->getMiddlewareGroups()['web'];
        $this->assertTrue(collect($web)->contains(fn ($m) => str_contains((string) $m, 'CsrfToken')));
    }

    public function test_csrf_token_is_required_when_verification_runs(): void
    {
        $author = $this->userWithRole('author');
        $this->actingAs($author);
        $request = \Illuminate\Http\Request::create('/account/media', 'POST', [], [], ['file' => $this->realImage('x.jpg')], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession($this->app['session']->driver());
        $middleware = new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($request, static fn () => response('passed'));
    }

    public function test_malicious_filenames_are_sanitized_and_stay_inside_uploads(): void
    {
        $data = $this->upload($this->realImage('../../../../etc/passwd .. évil <name>.jpg'))->assertCreated()->json('data');

        $media = Media::query()->findOrFail($data['id']);
        $this->assertMatchesRegularExpression('#^uploads/\d{4}/\d{2}/[a-z0-9-]+\.jpg$#', $media->path);
        $this->assertStringNotContainsString('..', $media->path);
        $this->assertStringStartsWith(str_replace('\\', '/', $this->mediaPublicPath.DIRECTORY_SEPARATOR.'uploads'), str_replace('\\', '/', $this->storedUploadFiles()[0]));
    }

    public function test_a_failure_after_the_file_is_written_leaves_no_row_and_no_file(): void
    {
        // The record step fails after the file was copied (e.g. the database rejects the insert).
        Media::creating(static function (): void {
            throw new \RuntimeException('database write failure /secret/path');
        });

        $this->upload($this->realImage('a.jpg'))->assertStatus(500)->assertJsonPath('error.code', 'media_upload_failed')
            ->assertJsonMissingPath('error.exception');
        $this->assertStringNotContainsString('secret', (string) $this->upload($this->realImage('b.jpg'))->getContent());

        $this->assertSame(0, Media::query()->withTrashed()->count());
        $this->assertSame([], $this->storedUploadFiles());
    }

    public function test_a_cancelled_record_write_is_reported_as_a_failure_and_leaves_no_file(): void
    {
        Media::creating(static fn (): bool => false);

        $this->upload($this->realImage('a.jpg'))->assertStatus(500)->assertJsonPath('error.code', 'media_upload_failed');
        $this->assertSame(0, Media::query()->withTrashed()->count());
        $this->assertSame([], $this->storedUploadFiles());
    }

    public function test_service_rejects_upload_without_permission_before_touching_disk(): void
    {
        $subscriber = $this->userWithRole('subscriber');

        try {
            app('cms.media.frontend')->uploadOwn($subscriber, $this->realImage('a.jpg'));
            $this->fail('expected denial');
        } catch (FrontendMediaException $e) {
            $this->assertSame('media_permission_denied', $e->errorCode);
        }
        $this->assertSame([], $this->storedUploadFiles());
    }
}
