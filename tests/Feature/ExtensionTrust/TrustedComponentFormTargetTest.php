<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ExtensionTrust\Concerns\TrustedComponentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault;

/**
 * CORE-EXTENSION-TRUST S2-R1 — a trusted component's forms may submit only to
 * targets its provider declared (named route + method + constrained
 * parameters) on routes its own plugin owns. Default deny.
 *
 * Every route used here is REAL and registered. A denial test also proves that
 * its route exists, or is reachable, so no test passes only because a fixture
 * route is missing. CSRF is enforced exactly as in S1-R1/S2.
 */
final class TrustedComponentFormTargetTest extends TestCase
{
    use RefreshDatabase;
    use TrustedComponentFixtures;

    private const OWNER = 's2r1-form';

    private const OTHER = 's2r1-other';

    private const SUBMIT = ['s2r1-form.submit', ['POST']];

    private object $code;

    private object $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        config(['app.url' => 'http://localhost']);

        Schema::create('s2r1_writes', function ($table) {
            $table->id();
            $table->string('route');
            $table->string('file')->nullable();
        });

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');

        $this->useComponentFixturePlugins();
        $this->writeComponentPlugin(self::OWNER);
        $this->writeComponentPlugin(self::OTHER);
        $this->activateComponentPlugins(self::OWNER, self::OTHER);
        $this->code = $this->pluginCode(self::OWNER);
        $this->other = $this->pluginCode(self::OTHER);

        // Owner-plugin routes: their action closures live in the owner's Plugin.php.
        $this->code->route(['POST'], '/s2r1/submit', 's2r1-form.submit', fn (Request $r) => $this->write('submit', $r));
        $this->code->route(['POST'], '/s2r1/undeclared', 's2r1-form.undeclared', fn (Request $r) => $this->write('undeclared', $r));
        $this->code->route(['POST'], '/s2r1/{locale}/submit', 's2r1-form.localized', fn (Request $r) => $this->write('localized', $r))->where('locale', 'en|vi');
        $this->code->route(['POST'], '/s2r1/u/{user}/save', 's2r1-form.user', fn (Request $r) => $this->write('user', $r));
        $this->code->route(['POST'], '/s2r1/opt/{tab?}', 's2r1-form.optional', fn (Request $r) => $this->write('optional', $r));
        $this->code->route(['POST'], '/s2r1/tab/{tab?}', 's2r1-form.tab', fn (Request $r) => $this->write('tab', $r))->defaults('tab', 'main');
        $this->code->route(['DELETE'], '/s2r1/item', 's2r1-form.item', fn (Request $r) => $this->write('item', $r));
        $this->code->route(['POST'], '/s2r1/nocsrf', 's2r1-form.nocsrf', fn (Request $r) => $this->write('nocsrf', $r))->withoutMiddleware(ValidateCsrfToken::class);
        $this->code->route(['POST'], '/s2r1/api', 's2r1-form.api', fn (Request $r) => $this->write('api', $r), []);
        $this->code->route(['POST'], '/s2r1/domain', 's2r1-form.domain', fn (Request $r) => $this->write('domain', $r))->domain('other.test');
        $this->promoteRoute($this->code->route(['GET'], '/s2r1/search', 's2r1-form.search', fn (Request $r) => $this->write('search', $r)));

        // Another active plugin's route, a host (test-code) route and a redirect route.
        $this->other->route(['POST'], '/s2r1-other/save', 's2r1-other.save', fn (Request $r) => $this->write('other', $r));
        Route::middleware('web')->post('/s2r1/host-save', fn (Request $r) => $this->write('host', $r))->name('s2r1-form.host');
        Route::middleware('web')->post('/s2r1/go', '\Illuminate\Routing\RedirectController')->defaults('destination', '/admin')->defaults('status', 302)->name('s2r1-form.go');
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function write(string $route, Request $request): mixed
    {
        DB::table('s2r1_writes')->insert(['route' => $route, 'file' => $request->file('attachment')?->getClientOriginalName()]);

        return redirect('/contact');
    }

    private function writes(?string $route = null): int
    {
        return $route === null ? DB::table('s2r1_writes')->count() : DB::table('s2r1_writes')->where('route', $route)->count();
    }

    /** Wrap a target in a plain multipart POST form carrying the CSRF token. */
    private static function form(string $action, string $method = 'post', string $extra = ''): string
    {
        return '<form method="'.$method.'" action="'.$action.'" enctype="multipart/form-data">'
            .($method === 'post' ? '<input type="hidden" name="_token" value="{csrf}">' : '')
            .$extra.'<label for="s2r1-n">Name</label><input type="text" id="s2r1-n" name="name"><button type="submit">Send</button></form>';
    }

    /**
     * Register a component of the OWNER plugin, publish a page invoking it and
     * GET that page. Returns the final HTML.
     */
    private function render(string $html, array $targets = [], array $contexts = ['public'], string $uri = '/contact', array $props = []): string
    {
        $provider = $this->provider(self::OWNER, 'form', $html, $contexts, ['@host'], null, [], '', $targets);
        $this->assertTrue($this->code->register($provider), 'registration');
        $this->code->shortcode('s2r1_form', self::OWNER.'/form');

        $atts = '';
        foreach ($props as $key => $value) {
            $atts .= ' '.$key.'="'.$value.'"';
        }

        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Contact', 'slug' => 'contact', 'content' => '<p>Write to us</p>[s2r1_form'.$atts.']']);

        return (string) $this->get($uri)->assertOk()->getContent();
    }

    private function assertLive(string $html, string $action): void
    {
        $this->assertStringContainsString('action="'.$action.'"', $html);
        $this->assertStringNotContainsString(TrustedSlotVault::FALLBACK, $html);
    }

    private function assertDenied(string $html, ?string $reason = null): void
    {
        $this->assertStringNotContainsString('<form', $html, 'no live form');
        $this->assertStringContainsString(TrustedSlotVault::FALLBACK, $html);
        $this->assertStringNotContainsString('TNCMSSLOT', $html);

        if ($reason !== null) {
            $this->assertContains('profile violation: '.$reason, $this->reasons());
        }
    }

    private function reasons(): array
    {
        return array_map(fn (array $d) => $d['reason'], $this->components()->diagnostics());
    }

    private function token(string $html): string
    {
        $this->assertSame(1, preg_match('#name="_token" value="([^"]*)"#', $html, $m));

        return $m[1];
    }

    private function submit(string $uri, array $fields): TestResponse
    {
        return $this->call('POST', $uri, $fields, [], ['attachment' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], $this->transformHeadersToServerVars(['Content-Type' => 'multipart/form-data']));
    }

    // ------------------------------------------------------------------
    // 1. Allowed: declared, owned, CSRF-protected named POST route
    // ------------------------------------------------------------------

    public function test_declared_owned_post_route_renders_and_a_js_free_multipart_submit_writes_once(): void
    {
        $html = $this->render(self::form('/s2r1/submit'), [self::SUBMIT]);

        $this->assertLive($html, '/s2r1/submit');
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);

        $token = $this->token($html);
        $this->submit('/s2r1/submit', ['_token' => $token, 'name' => 'Ada'])->assertRedirect('/contact');

        $this->assertSame(1, $this->writes('submit'));
        $this->assertSame('cv.pdf', DB::table('s2r1_writes')->value('file'));
    }

    public function test_authorized_form_response_is_not_publicly_cacheable(): void
    {
        $provider = $this->provider(self::OWNER, 'form', self::form('/s2r1/submit'), ['public'], ['@host'], null, [], '', [self::SUBMIT]);
        $this->assertTrue($this->code->register($provider));
        $this->code->shortcode('s2r1_form', self::OWNER.'/form');
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Contact', 'slug' => 'contact', 'content' => '[s2r1_form]']);

        $response = $this->get('/contact')->assertOk();

        $this->assertStringContainsString('action="/s2r1/submit"', (string) $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_canonical_absolute_action_from_route_helper_is_allowed(): void
    {
        $html = $this->render(self::form('{url:s2r1-form.submit}'), [self::SUBMIT]);

        $this->assertLive($html, 'http://localhost/s2r1/submit');
    }

    public function test_bad_csrf_tokens_on_the_authorized_route_get_419_and_zero_writes(): void
    {
        $html = $this->render(self::form('/s2r1/submit'), [self::SUBMIT]);
        $token = $this->token($html);

        $this->assertSame(419, $this->submit('/s2r1/submit', ['name' => 'x'])->getStatusCode());
        $this->assertSame(419, $this->submit('/s2r1/submit', ['_token' => '', 'name' => 'x'])->getStatusCode());
        $this->assertSame(419, $this->submit('/s2r1/submit', ['_token' => str_repeat('a', 40), 'name' => 'x'])->getStatusCode());

        // Cross-session: a fresh session posting the first session's token.
        $this->flushSession();
        $this->assertSame(419, $this->submit('/s2r1/submit', ['_token' => $token, 'name' => 'x'])->getStatusCode());
        $this->assertSame(0, $this->writes());
    }

    // ------------------------------------------------------------------
    // 2. Declaration-level denials
    // ------------------------------------------------------------------

    public function test_no_declaration_denies_a_form_on_an_owned_route(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/submit')), 'form target: undeclared');
    }

    public function test_wrong_route_declared_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/submit'), [['s2r1-form.undeclared', ['POST']]]), 'form target: undeclared');
    }

    public function test_wrong_method_declared_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/submit'), [['s2r1-form.submit', ['PUT']]]), 'form target: method');
    }

    public function test_a_different_components_declaration_does_not_authorize_this_component(): void
    {
        // Component "b" of the same plugin declares the route; component "form" does not.
        $declaring = $this->provider(self::OWNER, 'b', '<p>b</p>', ['public'], ['@host'], null, [], '', [self::SUBMIT]);
        $this->assertTrue($this->code->register($declaring));

        $this->assertDenied($this->render(self::form('/s2r1/submit')), 'form target: undeclared');
    }

    public function test_another_plugin_cannot_declare_and_target_the_owner_route(): void
    {
        $provider = $this->provider(self::OTHER, 'form', self::form('/s2r1/submit'), ['public'], ['@host'], null, [], '', [self::SUBMIT]);
        $this->assertTrue($this->other->register($provider));
        $this->other->shortcode('s2r1_other', self::OTHER.'/form');
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'O', 'slug' => 'o', 'content' => '[s2r1_other]']);

        $this->assertDenied((string) $this->get('/o')->assertOk()->getContent(), 'form target: not owned');
    }

    public function test_the_owner_cannot_declare_another_plugins_route(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1-other/save'), [['s2r1-other.save', ['POST']]]), 'form target: not owned');
    }

    public function test_inactive_owner_renders_nothing(): void
    {
        $provider = $this->provider(self::OWNER, 'form', self::form('/s2r1/submit'), ['public'], ['@host'], null, [], '', [self::SUBMIT]);
        $this->assertTrue($this->code->register($provider));
        $this->code->shortcode('s2r1_form', self::OWNER.'/form');
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Contact', 'slug' => 'contact', 'content' => '[s2r1_form]']);
        app('cms.extension')->deactivatePlugin(self::OWNER);
        app('cms.extension')->flushRegistry();

        $html = (string) $this->get('/contact')->assertOk()->getContent();

        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_editor_controlled_props_cannot_retarget_the_form(): void
    {
        // The provider (unwisely) echoes a prop into the action; only declared targets survive.
        $html = $this->render(self::form('{prop:action}'), [self::SUBMIT], props: ['action' => '/s2r1/undeclared']);
        $this->assertDenied($html, 'form target: undeclared');
    }

    public function test_declaration_is_snapshotted_at_registration(): void
    {
        $provider = $this->provider(self::OWNER, 'form', self::form('/s2r1/undeclared'), ['public'], ['@host'], null, [], '', [self::SUBMIT]);
        $this->assertTrue($this->code->register($provider));

        // Changing the provider's declaration afterwards has no effect.
        (fn () => $this->targets = [['s2r1-form.undeclared', ['POST']]])->call($provider);
        $this->code->shortcode('s2r1_form', self::OWNER.'/form');
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Contact', 'slug' => 'contact', 'content' => '[s2r1_form]']);

        $this->assertDenied((string) $this->get('/contact')->assertOk()->getContent(), 'form target: undeclared');
    }

    public function test_declared_route_that_no_longer_exists_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/gone'), [['s2r1-form.gone', ['POST']]]), 'form target: unknown route');
    }

    // ------------------------------------------------------------------
    // 3. Central regression: same-origin, non-admin, existing, but undeclared
    // ------------------------------------------------------------------

    public function test_existing_owned_same_origin_route_not_in_the_allowlist_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/undeclared'), [self::SUBMIT]), 'form target: undeclared');
        $this->assertTrue(Route::has('s2r1-form.undeclared'));
    }

    public function test_host_owned_same_origin_route_is_denied_even_when_declared(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/host-save'), [['s2r1-form.host', ['POST']]]), 'form target: not owned');
    }

    public function test_core_routes_are_never_targets(): void
    {
        $this->assertDenied($this->render(self::form('/', 'get'), [['cms.home', ['GET']]]), 'form target: not owned');
    }

    public function test_core_logout_is_not_a_target(): void
    {
        $this->assertTrue(Route::has('cms.auth.logout'), 'Core logout route is registered');
        $this->assertDenied($this->render(self::form('/logout'), [['cms.auth.logout', ['POST']]]), 'form target: not owned');
    }

    public function test_redirect_route_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/go'), [['s2r1-form.go', ['POST']]]), 'form target: not owned');
    }

    public function test_route_excluding_csrf_middleware_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/nocsrf'), [['s2r1-form.nocsrf', ['POST']]]), 'form target: unprotected');
    }

    public function test_route_outside_the_web_group_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/api'), [['s2r1-form.api', ['POST']]]), 'form target: unprotected');
    }

    public function test_route_in_the_csrf_except_list_is_denied(): void
    {
        ValidateCsrfToken::except(['s2r1/submit']);

        try {
            $this->assertDenied($this->render(self::form('/s2r1/submit'), [self::SUBMIT]), 'form target: unprotected');
        } finally {
            ValidateCsrfToken::flushState();
        }
    }

    public function test_domain_bound_route_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('http://localhost/s2r1/domain'), [['s2r1-form.domain', ['POST']]]));
    }

    // ------------------------------------------------------------------
    // Independent review findings (S2-R1 §9): route precedence, host routes,
    // middleware resolution, handler ownership
    // ------------------------------------------------------------------

    public function test_controller_route_owned_by_the_plugin_is_a_valid_target(): void
    {
        $controller = $this->loadComponentClass(self::OWNER, 'Controller');
        Route::middleware('web')->post('/s2r1/ctl', [$controller, 'store'])->name('s2r1-form.ctl');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertLive($this->render(self::form('/s2r1/ctl'), [['s2r1-form.ctl', ['POST']]]), '/s2r1/ctl');
    }

    public function test_handler_method_inherited_from_vendor_code_is_not_owned(): void
    {
        $inherited = $this->loadComponentClass(self::OWNER, 'Inherited');
        Route::middleware('web')->post('/s2r1/inherited', $inherited)->name('s2r1-form.inherited');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertDenied($this->render(self::form('/s2r1/inherited'), [['s2r1-form.inherited', ['POST']]]), 'form target: not owned');
    }

    public function test_csrf_excluded_through_a_middleware_alias_is_denied(): void
    {
        app('router')->aliasMiddleware('s2r1.csrf', ValidateCsrfToken::class);
        $this->code->route(['POST'], '/s2r1/alias', 's2r1-form.alias', fn (Request $r) => $this->write('alias', $r))->withoutMiddleware('s2r1.csrf');

        $this->assertDenied($this->render(self::form('/s2r1/alias'), [['s2r1-form.alias', ['POST']]]), 'form target: unprotected');

        // The router really skips CSRF there: a token-less POST is accepted.
        $this->submit('/s2r1/alias', [])->assertRedirect('/contact');
        $this->assertSame(1, $this->writes('alias'));
    }

    public function test_a_host_bound_route_on_the_same_path_blocks_the_target(): void
    {
        // On tenant.test the browser's POST /s2r1/shadow would reach the host-bound route.
        Route::middleware('web')->domain('tenant.test')->post('/s2r1/shadow', fn () => 'tenant')->name('s2r1.tenant');
        $this->code->route(['POST'], '/s2r1/shadow', 's2r1-form.shadow', fn (Request $r) => $this->write('shadow', $r));

        $this->assertDenied($this->render(self::form('/s2r1/shadow'), [['s2r1-form.shadow', ['POST']]]), 'form target: domain route');
    }

    public static function routeOrders(): array
    {
        return [
            'owner dynamic route first' => [true, 's2r1-form.dynamic', null],
            'other plugin static route first' => [false, 's2r1-other.static', 'form target: undeclared'],
        ];
    }

    /**
     * Review finding 1: the authorizer must pick the route dispatch picks, with
     * and without `route:cache` (CompiledRouteCollection / Symfony matcher).
     */
    #[DataProvider('routeOrders')]
    public function test_the_authorizer_agrees_with_dispatch_with_and_without_route_cache(bool $dynamicFirst, string $dispatched, ?string $verdict): void
    {
        $owner = $this->loadComponentClass(self::OWNER, 'Controller');
        $other = $this->loadComponentClass(self::OTHER, 'Controller');
        $router = app('router');
        $route = fn (string $uri, string $class, string $name) => (new \Illuminate\Routing\Route(['POST'], $uri, ['uses' => $class.'@__invoke', 'controller' => $class.'@__invoke', 'middleware' => ['web']]))
            ->name($name)->setRouter($router)->setContainer(app());

        // The OWNER's dynamic route and another plugin's overlapping static route.
        $dynamic = $route('s2r1c/{section}/submit', $owner, 's2r1-form.dynamic');
        $static = $route('s2r1c/contact/submit', $other, 's2r1-other.static');
        $collection = new \Illuminate\Routing\RouteCollection;
        foreach ($dynamicFirst ? [$dynamic, $static] : [$static, $dynamic] as $r) {
            $collection->add($r);
        }

        $authorize = new \TheNguyen\CMS\Support\TrustedComponents\TrustedFormTargetAuthorizer(app('cms.extension_identity'), self::OWNER, [
            new \TheNguyen\CMS\Support\TrustedComponents\TrustedFormTarget('s2r1-form.dynamic', ['POST'], ['section' => ['contact']]),
        ]);
        $dispatch = fn (): string => $router->getRoutes()->match(Request::create('http://localhost/s2r1c/contact/submit', 'POST'))->getName();

        $router->setRoutes($collection);
        $this->assertSame($dispatched, $dispatch(), 'uncached dispatch');
        $this->assertSame($verdict, $authorize('/s2r1c/contact/submit', 'POST'), 'uncached authorizer');

        $router->setCompiledRoutes($collection->compile());
        $this->assertInstanceOf(\Illuminate\Routing\CompiledRouteCollection::class, $router->getRoutes());
        $this->assertSame($dispatched, $dispatch(), 'cached dispatch');
        $this->assertSame($verdict, $authorize('/s2r1c/contact/submit', 'POST'), 'cached authorizer');
    }

    public function test_a_declared_owned_controller_target_is_authorized_under_route_cache(): void
    {
        $other = $this->loadComponentClass(self::OTHER, 'Controller');
        $router = app('router');
        $collection = new \Illuminate\Routing\RouteCollection;
        $collection->add((new \Illuminate\Routing\Route(['POST'], 's2r1c/contact/submit', ['uses' => $other.'@__invoke', 'controller' => $other.'@__invoke', 'middleware' => ['web']]))
            ->name('s2r1-other.static')->setRouter($router)->setContainer(app()));
        $router->setCompiledRoutes($collection->compile());

        $authorize = new \TheNguyen\CMS\Support\TrustedComponents\TrustedFormTargetAuthorizer(app('cms.extension_identity'), self::OTHER, [
            new \TheNguyen\CMS\Support\TrustedComponents\TrustedFormTarget('s2r1-other.static', ['POST']),
        ]);

        $this->assertNull($authorize('/s2r1c/contact/submit', 'POST'));
        $this->assertSame('form target: noncanonical', $authorize('/s2r1c/contact/submit/', 'POST'));
    }

    // ------------------------------------------------------------------
    // 4./5. URL shapes
    // ------------------------------------------------------------------

    public static function deniedActions(): array
    {
        return [
            'admin' => ['/admin/s2r1', 'form target: reserved path'],
            'livewire' => ['/livewire/update', 'form target: reserved path'],
            'external' => ['https://evil.test/s2r1/submit', 'form target: noncanonical'],
            'protocol-relative' => ['//evil.test/s2r1/submit', 'form target: noncanonical'],
            'scheme downgrade/upgrade' => ['https://localhost/s2r1/submit', 'form target: noncanonical'],
            'port' => ['http://localhost:8080/s2r1/submit', 'form target: noncanonical'],
            'userinfo' => ['http://u:p@localhost/s2r1/submit', 'form target: noncanonical'],
            'host suffix' => ['http://localhost.evil.test/s2r1/submit', 'form target: noncanonical'],
            'uppercase scheme' => ['HTTP://localhost/s2r1/submit', 'form target: noncanonical'],
            'javascript' => ['javascript:alert(1)', 'form target: noncanonical'],
            'javascript entity' => ['jav&#x61;script:alert(1)', 'form target: noncanonical'],
            'data' => ['data:text/html,x', 'form target: noncanonical'],
            'relative no slash' => ['s2r1/submit', 'form target: noncanonical'],
            'empty' => ['', 'form target: noncanonical'],
            'dot segment' => ['/s2r1/x/../submit', 'form target: noncanonical'],
            'dot' => ['/s2r1/./submit', 'form target: noncanonical'],
            'encoded slash' => ['/s2r1%2Fsubmit', 'form target: noncanonical'],
            'encoded char' => ['/s2r1/%73ubmit', 'form target: noncanonical'],
            'double encoded' => ['/s2r1/%2573ubmit', 'form target: noncanonical'],
            'backslash' => ['/s2r1\\submit', 'form target: noncanonical'],
            'query' => ['/s2r1/submit?x=1', 'form target: noncanonical'],
            'query method override' => ['/s2r1/submit?_method=DELETE', 'form target: noncanonical'],
            'fragment' => ['/s2r1/submit#top', 'form target: noncanonical'],
            'trailing slash' => ['/s2r1/submit/', 'form target: noncanonical'],
            'case variant' => ['/S2R1/Submit', 'form target: unknown route'],
            'double slash' => ['/s2r1//submit', 'form target: noncanonical'],
        ];
    }

    #[DataProvider('deniedActions')]
    public function test_non_canonical_or_foreign_action_is_denied(string $action, string $reason): void
    {
        $this->assertDenied($this->render(self::form($action), [self::SUBMIT]), $reason);
    }

    public function test_dot_segment_route_matching_is_real_so_the_denial_is_not_a_missing_route(): void
    {
        // Prove the router WOULD dispatch the decoded variant to the declared route.
        $route = app('router')->getRoutes()->match(Request::create('/s2r1/%73ubmit', 'POST'));
        $this->assertSame('s2r1-form.submit', $route->getName());
    }

    public function test_spoofed_host_header_cannot_authorize_an_absolute_action(): void
    {
        $html = $this->render(self::form('{url:s2r1-form.submit}'), [self::SUBMIT], uri: 'http://evil.test/contact');

        $this->assertStringNotContainsString('evil.test/s2r1', $html);
        $this->assertDenied($html, 'form target: noncanonical');
    }

    public function test_missing_action_is_denied_in_public_mode(): void
    {
        $this->assertDenied($this->render('<form method="post"><input type="hidden" name="_token" value="{csrf}"><button type="submit">Go</button></form>', [self::SUBMIT]), 'form: action missing');
    }

    // ------------------------------------------------------------------
    // 6. Method override and submitter overrides
    // ------------------------------------------------------------------

    public function test_hidden_method_spoof_to_a_declared_method_is_allowed_and_submits(): void
    {
        $html = $this->render(self::form('/s2r1/item', 'post', '<input type="hidden" name="_method" value="DELETE">'), [['s2r1-form.item', ['DELETE']]]);
        $this->assertLive($html, '/s2r1/item');

        $this->submit('/s2r1/item', ['_token' => $this->token($html), '_method' => 'DELETE'])->assertRedirect('/contact');
        $this->assertSame(1, $this->writes('item'));
    }

    public static function deniedMethodOverrides(): array
    {
        return [
            'undeclared spoof' => ['/s2r1/submit', '<input type="hidden" name="_method" value="DELETE">', 'post', 'form target: unknown route'],
            'spoof to PUT on POST-only' => ['/s2r1/item', '<input type="hidden" name="_method" value="PUT">', 'post', 'form target: unknown route'],
            'visible method' => ['/s2r1/item', '<input type="text" name="_method" value="DELETE">', 'post', 'form: method override'],
            'select method' => ['/s2r1/item', '<select name="_method"><option value="DELETE">d</option></select>', 'post', 'form: method override'],
            'button method' => ['/s2r1/item', '<button type="submit" name="_method" value="DELETE">x</button>', 'post', 'form: method override'],
            'duplicate' => ['/s2r1/item', '<input type="hidden" name="_method" value="DELETE"><input type="hidden" name="_method" value="DELETE">', 'post', 'form: method override'],
            'array name' => ['/s2r1/item', '<input type="hidden" name="_method[]" value="DELETE">', 'post', 'form: method override'],
            'case variant name' => ['/s2r1/item', '<input type="hidden" name="_METHOD" value="DELETE">', 'post', 'form: method override'],
            'GET value' => ['/s2r1/search', '<input type="hidden" name="_method" value="GET">', 'post', 'form: method override'],
            'lowercase value' => ['/s2r1/item', '<input type="hidden" name="_method" value="delete">', 'post', 'form: method override'],
            'in a GET form' => ['/s2r1/search', '<input type="hidden" name="_method" value="DELETE">', 'get', 'form: method override'],
            'disabled spoof field' => ['/s2r1/item', '<input type="hidden" name="_method" value="DELETE" disabled>', 'post', 'form: method override'],
            'spoof field in a disabled fieldset' => ['/s2r1/item', '<fieldset disabled><input type="hidden" name="_method" value="DELETE"></fieldset>', 'post', 'form: method override'],
        ];
    }

    public function test_method_spoof_is_refused_when_the_body_would_not_be_parsed(): void
    {
        // text/plain bodies are not parsed, so the browser's POST would reach a POST route, not DELETE.
        $html = '<form method="post" action="/s2r1/item" enctype="text/plain"><input type="hidden" name="_method" value="DELETE"><button type="submit">x</button></form>';

        $this->assertDenied($this->render($html, [['s2r1-form.item', ['DELETE']]]), 'form: method override');
    }

    #[DataProvider('deniedMethodOverrides')]
    public function test_method_override_cannot_escape_authorization(string $action, string $extra, string $method, string $reason): void
    {
        $targets = [self::SUBMIT, ['s2r1-form.item', ['DELETE']], ['s2r1-form.search', ['GET']]];

        $this->assertDenied($this->render(self::form($action, $method, $extra), $targets), $reason);
    }

    public static function submitterOverrides(): array
    {
        return [
            'button formaction' => ['<button type="submit" formaction="/s2r1/undeclared">x</button>'],
            'input formaction' => ['<input type="submit" formaction="/s2r1/undeclared" value="x">'],
            'button formmethod' => ['<button type="submit" formmethod="get">x</button>'],
            'input formmethod' => ['<input type="submit" formmethod="get" value="x">'],
            'formenctype' => ['<button type="submit" formenctype="text/plain">x</button>'],
            'formtarget' => ['<button type="submit" formtarget="_blank">x</button>'],
            'formnovalidate' => ['<button type="submit" formnovalidate>x</button>'],
            'form association' => ['<input type="text" name="z" form="elsewhere">'],
        ];
    }

    #[DataProvider('submitterOverrides')]
    public function test_submitter_overrides_are_rejected(string $extra): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/submit', 'post', $extra), [self::SUBMIT, ['s2r1-form.undeclared', ['GET']]]), 'form: submitter override');
    }

    public function test_one_unauthorized_form_among_several_rejects_the_whole_component(): void
    {
        $html = $this->render(self::form('/s2r1/submit').self::form('/s2r1/undeclared'), [self::SUBMIT]);

        $this->assertDenied($html, 'form target: undeclared');
    }

    public function test_two_authorized_forms_render(): void
    {
        $html = $this->render(self::form('/s2r1/submit').self::form('/s2r1/vi/submit'), [self::SUBMIT, ['s2r1-form.localized', ['POST'], ['locale' => ['vi']]]]);

        $this->assertSame(2, substr_count($html, '<form '));
    }

    public function test_nested_form_is_rejected(): void
    {
        $this->assertDenied($this->render('<form method="post" action="/s2r1/submit"><form method="post" action="/s2r1/submit"></form></form>', [self::SUBMIT]));
    }

    // ------------------------------------------------------------------
    // GET forms
    // ------------------------------------------------------------------

    public function test_declared_get_form_is_allowed(): void
    {
        $this->assertLive($this->render(self::form('/s2r1/search', 'get'), [['s2r1-form.search', ['GET']]]), '/s2r1/search');
    }

    public function test_get_form_with_no_method_attribute_is_treated_as_get(): void
    {
        // GET /s2r1/submit is Core's frontend catch-all, never the declared POST route.
        $html = '<form action="/s2r1/submit"><label for="q">Q</label><input type="text" id="q" name="q"></form>';

        $this->assertDenied($this->render($html, [self::SUBMIT]), 'form target: undeclared');
    }

    public function test_undeclared_get_form_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/search', 'get'), [self::SUBMIT]), 'form target: undeclared');
    }

    public function test_csrf_token_in_a_get_form_is_rejected(): void
    {
        $html = self::form('/s2r1/search', 'get', '<input type="hidden" name="_token" value="{csrf}">');

        $this->assertDenied($this->render($html, [['s2r1-form.search', ['GET']]]), 'form: csrf in get');
    }

    // ------------------------------------------------------------------
    // 7. Route parameters
    // ------------------------------------------------------------------

    public function test_declared_locale_parameter_value_is_allowed_and_submits(): void
    {
        $html = $this->render(self::form('/s2r1/vi/submit'), [['s2r1-form.localized', ['POST'], ['locale' => ['en', 'vi']]]]);
        $this->assertLive($html, '/s2r1/vi/submit');

        $this->submit('/s2r1/vi/submit', ['_token' => $this->token($html)])->assertRedirect('/contact');
        $this->assertSame(1, $this->writes('localized'));
    }

    public function test_undeclared_locale_parameter_value_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/en/submit'), [['s2r1-form.localized', ['POST'], ['locale' => ['vi']]]]), 'form target: parameter');
    }

    public function test_undeclared_parameter_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/vi/submit'), [['s2r1-form.localized', ['POST']]]), 'form target: parameter');
    }

    public function test_declared_user_parameter_value_is_allowed(): void
    {
        $targets = [['s2r1-form.user', ['POST'], ['user' => ['5']]]];

        $this->assertLive($this->render(self::form('/s2r1/u/5/save'), $targets), '/s2r1/u/5/save');
    }

    public function test_user_parameter_substitution_to_another_user_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/u/7/save'), [['s2r1-form.user', ['POST'], ['user' => ['5']]]]), 'form target: parameter');
    }

    public function test_optional_parameter_absent_is_allowed_present_needs_declaration(): void
    {
        $this->assertLive($this->render(self::form('/s2r1/opt'), [['s2r1-form.optional', ['POST']]]), '/s2r1/opt');
    }

    public function test_action_relying_on_a_route_default_is_not_the_canonical_url(): void
    {
        // The router WOULD dispatch '/s2r1/tab' to the declared route with tab=main, and
        // every pre-check passes; only byte equality with route() output refuses it.
        $route = app('router')->getRoutes()->match(Request::create('/s2r1/tab', 'POST'));
        $this->assertSame(['s2r1-form.tab', 'main'], [$route->getName(), $route->parameter('tab')]);

        $this->assertDenied($this->render(self::form('/s2r1/tab'), [['s2r1-form.tab', ['POST'], ['tab' => ['main']]]]), 'form target: noncanonical');
    }

    public function test_action_with_the_explicit_default_value_is_canonical(): void
    {
        $this->assertLive($this->render(self::form('/s2r1/tab/main'), [['s2r1-form.tab', ['POST'], ['tab' => ['main']]]]), '/s2r1/tab/main');
    }

    public function test_optional_parameter_present_but_undeclared_is_denied(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/opt/admin'), [['s2r1-form.optional', ['POST']]]), 'form target: parameter');
    }

    // ------------------------------------------------------------------
    // Registration-time validation of declarations
    // ------------------------------------------------------------------

    public static function invalidDeclarations(): array
    {
        return [
            'empty route' => [[['', ['POST']]]],
            'bad route chars' => [[['s2r1 form', ['POST']]]],
            'route too long' => [[[str_repeat('a', 129), ['POST']]]],
            'no methods' => [[['s2r1-form.submit', []]]],
            'lowercase method' => [[['s2r1-form.submit', ['post']]]],
            'HEAD' => [[['s2r1-form.submit', ['HEAD']]]],
            'OPTIONS' => [[['s2r1-form.submit', ['OPTIONS']]]],
            'duplicate method' => [[['s2r1-form.submit', ['POST', 'POST']]]],
            'duplicate route' => [[self::SUBMIT, self::SUBMIT]],
            'empty values' => [[['s2r1-form.user', ['POST'], ['user' => []]]]],
            'non-list values' => [[['s2r1-form.user', ['POST'], ['user' => '5']]]],
            'non-string value' => [[['s2r1-form.user', ['POST'], ['user' => [5]]]]],
            'slash value' => [[['s2r1-form.user', ['POST'], ['user' => ['5/../7']]]]],
            'empty string value' => [[['s2r1-form.user', ['POST'], ['user' => ['']]]]],
            'bad param name' => [[['s2r1-form.user', ['POST'], ['us er' => ['5']]]]],
            'not a target object' => [['s2r1-form.submit']],
            'too many' => [array_map(fn ($i) => ['s2r1-form.r'.$i, ['POST']], range(1, 17))],
        ];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_invalid_declarations_reject_the_registration(array $targets): void
    {
        $provider = $this->provider(self::OWNER, 'form', '<p>x</p>', ['public'], ['@host'], null, [], '', $targets);

        $this->assertFalse($this->code->register($provider));
        $this->assertContains('invalid policy: form targets', $this->reasons());
    }

    // ------------------------------------------------------------------
    // 9./10. Preview, isolation, unrelated pages
    // ------------------------------------------------------------------

    public function test_preview_never_renders_a_live_form_even_for_a_declared_target(): void
    {
        $provider = $this->provider(self::OWNER, 'form', self::form('/s2r1/submit'), ['public', 'preview'], ['@host'], null, [], '', [self::SUBMIT]);
        $this->assertTrue($this->code->register($provider));
        $this->code->shortcode('s2r1_form', self::OWNER.'/form');
        $draft = app('cms.content')->create(['type' => 'page', 'status' => 'draft', 'locale' => 'en', 'title' => 'D', 'slug' => 'd', 'content' => '[s2r1_form]']);

        $response = $this->get(app('cms.preview')->temporaryUrl($draft))->assertOk();

        $this->assertStringNotContainsString('<form', (string) $response->getContent());
        $this->assertSame('must-revalidate, no-cache, no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame(0, $this->writes());
    }

    public function test_denials_do_not_leak_into_the_next_request(): void
    {
        $this->assertDenied($this->render(self::form('/s2r1/undeclared'), [self::SUBMIT]), 'form target: undeclared');

        $about = app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'About', 'slug' => 'about', 'content' => '<p>About us</p>']);
        $response = $this->get('/about')->assertOk();

        $this->assertNotNull($about);
        $this->assertSame([], $this->reasons());
        $this->assertStringContainsString('<p>About us</p>', (string) $response->getContent());
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
    }

    public function test_forged_placeholders_in_content_are_still_stripped(): void
    {
        $html = $this->render(self::form('/s2r1/submit'), [self::SUBMIT]);
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'F', 'slug' => 'f', 'content' => '<div class="tncms-slot">TNCMSSLOT'.str_repeat('a', 32).'</div><p>f</p>']);

        $forged = (string) $this->get('/f')->assertOk()->getContent();

        $this->assertStringContainsString('action="/s2r1/submit"', $html);
        $this->assertStringNotContainsString('TNCMSSLOT', $forged);
        $this->assertStringNotContainsString('<form', $forged);
    }

    public function test_undeclared_submit_routes_still_work_for_their_own_callers(): void
    {
        // S2-R1 only governs component OUTPUT; it never blocks a route itself.
        $this->get('/');
        $this->submit('/s2r1/undeclared', ['_token' => session()->token()])->assertRedirect('/contact');
        $this->assertSame(1, $this->writes('undeclared'));
    }
}
