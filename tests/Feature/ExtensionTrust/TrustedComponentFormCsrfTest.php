<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ExtensionTrust\Concerns\TrustedComponentFixtures;
use Tests\TestCase;

/**
 * CORE-EXTENSION-TRUST S2 — a disposable fixture form rendered through the
 * trusted slot pipeline, submitted as a plain (JavaScript-free) multipart POST
 * with Laravel's real CSRF verification (brief §3.5, acceptance S2-1/S2-2/S2-5).
 *
 * Laravel skips CSRF verification while running unit tests; as in S1-R1 a
 * subclass removes ONLY that bypass, so the framework's own comparison runs.
 */
final class TrustedComponentFormCsrfTest extends TestCase
{
    use RefreshDatabase;
    use TrustedComponentFixtures;

    private const FORM_WITH_STATE = '<form method="post" action="/s2-fixture/submit" enctype="multipart/form-data">'
        .'<input type="hidden" name="_token" value="{csrf}">'
        .'<label for="s2-name">Name</label><input type="text" id="s2-name" name="name" value="{old:name}">'
        .'<p class="s2-error">{error:name}</p>'
        .'<label for="s2-file">File</label><input type="file" id="s2-file" name="attachment">'
        .'<button type="submit">Send</button></form>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            /** Production behaviour: never skip verification because PHPUnit is running. */
            protected function runningUnitTests()
            {
                return false;
            }
        });

        Schema::create('s2_fixture_writes', function ($table) {
            $table->id();
            $table->unsignedInteger('v');
            $table->string('name')->nullable();
            $table->string('file')->nullable();
        });

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');

        $this->useComponentFixturePlugins();
        $this->writeComponentPlugin('s2-form');
        $this->activateComponentPlugins('s2-form');
        $code = $this->pluginCode('s2-form');
        // S2-R1 migration: the form target is declared and owned by s2-form.
        $this->assertTrue($code->register($this->provider('s2-form', 'form', self::FORM_WITH_STATE, ['public'], ['@host'], null, [], '', [['s2.fixture.submit', ['POST']]])));
        $code->shortcode('s2_form', 's2-form/form');

        // The disposable fixture endpoint: the ONLY place a mutation happens.
        $code->route(['POST'], '/s2-fixture/submit', 's2.fixture.submit', function (Request $request) {
            $data = $request->validate(['name' => 'required|string|max:50', 'attachment' => 'nullable|file|max:64']);
            DB::table('s2_fixture_writes')->insert(['v' => 1, 'name' => $data['name'], 'file' => $request->file('attachment')?->getClientOriginalName()]);

            return redirect('/contact')->with('s2_status', 'sent');
        });

        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Contact', 'slug' => 'contact', 'content' => '<p>Write to us</p>[s2_form]']);
    }

    /** GET the page and return the CSRF token the final HTML form carries. */
    private function formToken(): string
    {
        $html = (string) $this->get('/contact')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<form method="post" action="/s2-fixture/submit" enctype="multipart/form-data"><input type="hidden" name="_token" value="([^"]*)">#', $html, $m), 'form + CSRF field in the final response');

        return $m[1];
    }

    private function submit(?string $token, array $extra = [], array $headers = []): TestResponse
    {
        $fields = $extra + ['name' => 'Ada', 'attachment' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')];

        if ($token !== null) {
            $fields['_token'] = $token;
        }

        return $this->call('POST', '/s2-fixture/submit', array_diff_key($fields, ['attachment' => 1]), [], ['attachment' => $fields['attachment']], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'multipart/form-data']));
    }

    private function writes(): int
    {
        return DB::table('s2_fixture_writes')->count();
    }

    public function test_control_the_framework_bypass_is_removed(): void
    {
        $this->formToken();
        $this->assertSame(419, $this->submit(null)->getStatusCode());
    }

    public function test_positive_multipart_post_with_the_rendered_token_succeeds(): void
    {
        $token = $this->formToken();
        $this->assertSame(session()->token(), $token);

        $response = $this->submit($token);

        $response->assertRedirect('/contact');
        $this->assertSame(1, $this->writes());
        $this->assertSame(['Ada', 'cv.pdf'], array_values((array) DB::table('s2_fixture_writes')->first(['name', 'file'])));
    }

    public function test_validation_errors_and_old_input_round_trip_through_the_redirect(): void
    {
        $token = $this->formToken();

        $this->submit($token, ['name' => str_repeat('x', 51)])->assertRedirect();
        $this->assertSame(0, $this->writes());

        $html = (string) $this->get('/contact')->assertOk()->getContent();
        $this->assertStringContainsString('value="'.str_repeat('x', 51).'"', $html);
        $this->assertMatchesRegularExpression('#<p class="s2-error">[^<]+</p>#', $html);
    }

    /** @return array<string, ?string> */
    private function badTokens(string $valid): array
    {
        return [
            'missing' => null,
            'empty' => '',
            'forged' => str_repeat('a', 40),
            'truncated' => substr($valid, 0, 20),
            'wrong case' => strtoupper($valid) === $valid ? strtolower($valid) : strtoupper($valid),
            'extended' => $valid.'x',
        ];
    }

    public function test_invalid_tokens_are_419_with_zero_mutations(): void
    {
        $valid = $this->formToken();

        foreach ($this->badTokens($valid) as $label => $token) {
            $this->assertSame(419, $this->submit($token)->getStatusCode(), $label);
        }

        $this->assertSame(419, $this->submit(null, [], ['X-CSRF-TOKEN' => str_repeat('b', 40)])->getStatusCode(), 'forged header');
        $this->assertSame(0, $this->writes());
    }

    public function test_foreign_session_token_is_419_with_zero_mutations(): void
    {
        $tokenA = $this->formToken();

        $this->app['session.store']->invalidate();
        $tokenB = $this->formToken();
        $this->assertNotSame($tokenA, $tokenB);

        $this->assertSame(419, $this->submit($tokenA)->getStatusCode());
        $this->assertSame(0, $this->writes());

        // Session B's own token works.
        $this->submit($tokenB)->assertRedirect('/contact');
        $this->assertSame(1, $this->writes());
    }

    public function test_token_rotation_invalidates_the_old_rendered_form(): void
    {
        $old = $this->formToken();

        // Logout / login rotate the session token (Session::regenerateToken()).
        $this->app['session.store']->regenerateToken();
        $fresh = $this->formToken();
        $this->assertNotSame($old, $fresh);

        $this->assertSame(419, $this->submit($old)->getStatusCode());
        $this->assertSame(0, $this->writes());
        $this->submit($fresh)->assertRedirect('/contact');
        $this->assertSame(1, $this->writes());
    }
}
