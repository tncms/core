<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Support\TrustedScripts\JsonDuplicateKeys;
use TheNguyen\CMS\Support\TrustedScripts\StrictHttpsUrl;
use TheNguyen\CMS\Support\TrustedScripts\TrustedScriptManifestParser;

/**
 * CORE-EXTENSION-TRUST S1 — Gate D: URL grammar (T7 / N4), CSP declaration
 * grammar (T6 / N5 / N6), duplicate keys (N8), schema strictness, fingerprint.
 * Pure parsing — no app, network or database.
 */
final class TrustedScriptDeclarationPolicyTest extends TestCase
{
    private const TURNSTILE = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private function entry(array $overrides = []): array
    {
        return array_replace([
            'url' => self::TURNSTILE,
            'attributes' => ['async' => true, 'defer' => true],
            'csp' => ['script-src' => ['https://challenges.cloudflare.com'], 'frame-src' => ['https://challenges.cloudflare.com']],
            'purpose' => 'Turnstile',
        ], $overrides);
    }

    private function parse(array $scripts): array
    {
        return TrustedScriptManifestParser::parse('owner', (string) json_encode(['name' => 'x', 'trusted_scripts' => $scripts], JSON_UNESCAPED_SLASHES));
    }

    // ---- URL grammar --------------------------------------------------------

    public function test_turnstile_url_is_accepted_unchanged(): void
    {
        $this->assertSame(self::TURNSTILE, StrictHttpsUrl::normalize(self::TURNSTILE));
        $this->assertSame('https://challenges.cloudflare.com', StrictHttpsUrl::origin(self::TURNSTILE));
    }

    public function test_query_is_kept_exactly_and_port_443_is_dropped(): void
    {
        $this->assertSame('https://cdn.example.com/a.js?render=explicit&b=1', StrictHttpsUrl::normalize('https://cdn.example.com:443/a.js?render=explicit&b=1'));
        $this->assertSame('https://cdn.example.com/a.js?b=1&a=2', StrictHttpsUrl::normalize('https://cdn.example.com/a.js?b=1&a=2'));
    }

    public function test_host_is_lowercased_and_idn_converted_to_punycode(): void
    {
        $this->assertSame('https://cdn.example.com/a.js', StrictHttpsUrl::normalize('https://CDN.Example.COM/a.js'));
        $this->assertSame('https://xn--bcher-kva.de/a.js', StrictHttpsUrl::normalize('https://bücher.de/a.js'));
        $this->assertSame('https://xn--bcher-kva.de/a.js', StrictHttpsUrl::normalize('https://xn--bcher-kva.de/a.js'));
    }

    /** @return array<string, array{string}> */
    public static function hostileUrls(): array
    {
        return [
            'http' => ['http://challenges.cloudflare.com/turnstile/v0/api.js'],
            'mixed-case scheme' => ['HTTPS://challenges.cloudflare.com/x.js'],
            'protocol-relative' => ['//challenges.cloudflare.com/x.js'],
            'javascript' => ['javascript:alert(1)'],
            'javascript authority' => ['javascript://challenges.cloudflare.com/%0aalert(1)'],
            'data' => ['data:text/javascript,alert(1)'],
            'data authority' => ['data://challenges.cloudflare.com/,alert(1)'],
            'ftp' => ['ftp://challenges.cloudflare.com/x.js'],
            'blob' => ['blob:https://challenges.cloudflare.com/uuid'],
            'userinfo' => ['https://user:pass@challenges.cloudflare.com/x.js'],
            'userinfo no pass' => ['https://user@challenges.cloudflare.com/x.js'],
            'nonstandard port' => ['https://challenges.cloudflare.com:8443/x.js'],
            'port 80' => ['https://challenges.cloudflare.com:80/x.js'],
            'ipv4' => ['https://127.0.0.1/x.js'],
            'ipv4 public' => ['https://8.8.8.8/x.js'],
            'ipv6' => ['https://[::1]/x.js'],
            'localhost' => ['https://localhost/x.js'],
            'sub localhost' => ['https://a.localhost/x.js'],
            'internal' => ['https://svc.internal/x.js'],
            'local' => ['https://printer.local/x.js'],
            'test tld' => ['https://site.test/x.js'],
            'single label' => ['https://intranet/x.js'],
            'trailing dot' => ['https://challenges.cloudflare.com./x.js'],
            'fragment' => ['https://challenges.cloudflare.com/x.js#frag'],
            'dot-dot segment' => ['https://challenges.cloudflare.com/a/../x.js'],
            'dot segment' => ['https://challenges.cloudflare.com/./x.js'],
            'encoded dots' => ['https://challenges.cloudflare.com/%2e%2e/x.js'],
            'encoded slash' => ['https://challenges.cloudflare.com/a%2Fb.js'],
            'encoded backslash' => ['https://challenges.cloudflare.com/a%5cb.js'],
            'encoded nul' => ['https://challenges.cloudflare.com/a%00.js'],
            'malformed percent' => ['https://challenges.cloudflare.com/a%zz.js'],
            'backslash' => ['https://challenges.cloudflare.com\\x.js'],
            'space' => ['https://challenges.cloudflare.com/a b.js'],
            'newline' => ["https://challenges.cloudflare.com/a\n.js"],
            'tab' => ["https://challenges.cloudflare.com/a\t.js"],
            'no path' => ['https://challenges.cloudflare.com'],
            'double slash path' => ['https://challenges.cloudflare.com//evil.com/x.js'],
            'empty query' => ['https://challenges.cloudflare.com/x.js?'],
            'quote in path' => ['https://challenges.cloudflare.com/x.js"onload="'],
            'too long' => ['https://challenges.cloudflare.com/'.str_repeat('a', 2100)],
            'empty' => [''],
        ];
    }

    #[DataProvider('hostileUrls')]
    public function test_hostile_urls_fail_the_grammar(string $url): void
    {
        $this->assertNull(StrictHttpsUrl::normalize($url), $url);
    }

    #[DataProvider('hostileUrls')]
    public function test_hostile_urls_reject_the_whole_declaration(string $url): void
    {
        $result = $this->parse(['ts' => $this->entry(['url' => $url])]);

        $this->assertSame([], $result['declarations']);
        $this->assertSame('invalid url', $result['errors'][0]['reason']);
    }

    // ---- CSP grammar --------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function hostileCsp(): array
    {
        return [
            'wildcard' => [['script-src' => ['*']]],
            'wildcard host' => [['script-src' => ['https://*.cloudflare.com']]],
            'scheme only' => [['script-src' => ['https:']]],
            'unsafe-inline' => [['script-src' => ["'unsafe-inline'"]]],
            'unsafe-eval' => [['script-src' => ["'unsafe-eval'"]]],
            'strict-dynamic' => [['script-src' => ["'strict-dynamic'"]]],
            'data' => [['script-src' => ['data:']]],
            'blob' => [['script-src' => ['blob:']]],
            'http origin' => [['script-src' => ['http://challenges.cloudflare.com']]],
            'path in source' => [['script-src' => ['https://challenges.cloudflare.com/turnstile/']]],
            'trailing slash' => [['script-src' => ['https://challenges.cloudflare.com/']]],
            'port' => [['script-src' => ['https://challenges.cloudflare.com:8443']]],
            'unknown directive' => [['script-src' => ['https://challenges.cloudflare.com'], 'default-src' => ['https://challenges.cloudflare.com']]],
            'report-uri' => [['script-src' => ['https://challenges.cloudflare.com'], 'report-uri' => ['https://evil.example.com']]],
            'empty sources' => [['script-src' => []]],
            'not a list' => [['script-src' => 'https://challenges.cloudflare.com']],
            'empty csp' => [[]],
        ];
    }

    #[DataProvider('hostileCsp')]
    public function test_hostile_csp_rejects_the_declaration(array $csp): void
    {
        $result = $this->parse(['ts' => $this->entry(['csp' => $csp])]);

        $this->assertSame([], $result['declarations']);
        $this->assertSame('invalid csp', $result['errors'][0]['reason']);
    }

    public function test_script_src_must_include_the_script_origin(): void
    {
        $result = $this->parse(['ts' => $this->entry(['csp' => ['script-src' => ['https://other.example.com']]])]);

        $this->assertSame([], $result['declarations']);
        $this->assertSame('script-src must include the script origin', $result['errors'][0]['reason']);
    }

    public function test_csp_is_normalised_deduplicated_and_sorted(): void
    {
        $result = $this->parse(['ts' => $this->entry(['csp' => [
            'script-src' => ['https://Challenges.Cloudflare.com', 'https://challenges.cloudflare.com'],
            'connect-src' => ['https://b.example.com', 'https://a.example.com'],
        ]])]);

        $declaration = $result['declarations'][0];
        $this->assertSame(['connect-src', 'script-src'], array_keys($declaration->csp));
        $this->assertSame(['https://challenges.cloudflare.com'], $declaration->csp['script-src']);
        $this->assertSame(['https://a.example.com', 'https://b.example.com'], $declaration->csp['connect-src']);
    }

    // ---- Duplicate keys + schema --------------------------------------------

    public function test_duplicate_script_key_rejects_every_script_of_the_manifest(): void
    {
        $raw = '{"name":"x","trusted_scripts":{"ts":'.json_encode($this->entry(), JSON_UNESCAPED_SLASHES)
            .',"ts":'.json_encode($this->entry(['url' => 'https://evil.example.com/x.js', 'csp' => ['script-src' => ['https://evil.example.com']]]), JSON_UNESCAPED_SLASHES).'}}';

        $result = TrustedScriptManifestParser::parse('owner', $raw);

        $this->assertSame([], $result['declarations']);
        $this->assertSame('duplicate json key in manifest', $result['errors'][0]['reason']);
    }

    public function test_duplicate_nested_or_escaped_keys_are_detected(): void
    {
        $this->assertSame(['/a/url'], JsonDuplicateKeys::find('{"a":{"url":"x","url":"y"}}'));
        $this->assertSame(['/k'], JsonDuplicateKeys::find('{"k":1,"k":2}'));
        $this->assertSame([], JsonDuplicateKeys::find('{"a":[{"k":1},{"k":2}],"b":"{\"k\":1,\"k\":2}"}'));
        $this->assertNull(JsonDuplicateKeys::find('{"a":'));
    }

    public function test_duplicate_key_elsewhere_in_manifest_also_fails_closed(): void
    {
        $raw = '{"name":"x","slug":"a","slug":"b","trusted_scripts":{"ts":'.json_encode($this->entry(), JSON_UNESCAPED_SLASHES).'}}';

        $this->assertSame([], TrustedScriptManifestParser::parse('owner', $raw)['declarations']);
    }

    public function test_trusted_scripts_must_be_an_object(): void
    {
        $result = TrustedScriptManifestParser::parse('owner', '{"trusted_scripts":[{"url":"https://a.example.com/x.js"}]}');

        $this->assertSame([], $result['declarations']);
        $this->assertSame('trusted_scripts must be an object', $result['errors'][0]['reason']);
    }

    public function test_absent_block_yields_nothing_and_no_errors(): void
    {
        $this->assertSame(['declarations' => [], 'errors' => []], TrustedScriptManifestParser::parse('owner', '{"name":"x"}'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidEntries(): array
    {
        return [
            'unknown field' => [['nonce' => 'abc'], 'unknown field'],
            'bad position' => [['position' => 'body'], 'invalid position'],
            'onload attribute' => [['attributes' => ['onload' => 'x()']], 'invalid attributes'],
            'type module' => [['attributes' => ['type' => 'module']], 'invalid attributes'],
            'nonce attribute' => [['attributes' => ['nonce' => 'abc']], 'invalid attributes'],
            'async not bool' => [['attributes' => ['async' => 'yes']], 'invalid attributes'],
            'bad crossorigin' => [['attributes' => ['crossorigin' => 'x']], 'invalid attributes'],
            'bad integrity' => [['attributes' => ['integrity' => 'md5-abc']], 'invalid attributes'],
            'reserved dep' => [['deps' => ['ext:other/x']], 'invalid deps'],
            'bad dep' => [['deps' => ['a b']], 'invalid deps'],
            'missing purpose' => [['purpose' => ''], 'invalid purpose'],
            'control char purpose' => [['purpose' => "a\nb"], 'invalid purpose'],
            'missing url' => [['url' => null], 'invalid url'],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_entries_are_rejected_whole(array $overrides, string $reason): void
    {
        $result = $this->parse(['ts' => $this->entry($overrides), 'ok' => $this->entry()]);

        $this->assertCount(1, $result['declarations']);
        $this->assertSame('ok', $result['declarations'][0]->key);
        $this->assertSame(['owner' => 'owner', 'key' => 'ts', 'reason' => $reason], $result['errors'][0]);
    }

    public function test_invalid_script_keys_are_rejected(): void
    {
        foreach (['Upper', 'with/slash', 'with.dot', '-lead', str_repeat('a', 65), 'ext:x'] as $key) {
            $result = $this->parse([$key => $this->entry()]);
            $this->assertSame([], $result['declarations'], $key);
        }
    }

    public function test_too_many_scripts_is_rejected(): void
    {
        $scripts = [];

        for ($i = 0; $i < 17; $i++) {
            $scripts['s'.$i] = $this->entry();
        }

        $this->assertSame([], $this->parse($scripts)['declarations']);
    }

    // ---- Fingerprint ---------------------------------------------------------

    public function test_fingerprint_is_deterministic_and_order_independent(): void
    {
        $a = $this->parse(['ts' => $this->entry()])['declarations'][0];
        $b = $this->parse(['ts' => [
            'purpose' => 'Turnstile',
            'csp' => ['frame-src' => ['https://challenges.cloudflare.com'], 'script-src' => ['https://challenges.cloudflare.com']],
            'attributes' => ['defer' => true, 'async' => true],
            'url' => self::TURNSTILE,
        ]])['declarations'][0];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->fingerprint);
        $this->assertSame($a->fingerprint, $b->fingerprint);
        $this->assertSame('ext:owner/ts', $a->handle());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function materialChanges(): array
    {
        return [
            'url' => [['url' => 'https://challenges.cloudflare.com/turnstile/v1/api.js']],
            'query' => [['url' => self::TURNSTILE.'?render=explicit']],
            'position' => [['position' => 'head']],
            'attributes' => [['attributes' => ['async' => true]]],
            'integrity' => [['attributes' => ['async' => true, 'defer' => true, 'integrity' => 'sha384-abc']]],
            'deps' => [['deps' => ['tncms.frontend']]],
            'csp added origin' => [['csp' => ['script-src' => ['https://challenges.cloudflare.com'], 'frame-src' => ['https://challenges.cloudflare.com'], 'connect-src' => ['https://api.example.com']]]],
            'purpose' => [['purpose' => 'Something else']],
        ];
    }

    #[DataProvider('materialChanges')]
    public function test_any_material_change_changes_the_fingerprint(array $overrides): void
    {
        $before = $this->parse(['ts' => $this->entry()])['declarations'][0];
        $after = $this->parse(['ts' => $this->entry($overrides)])['declarations'][0];

        $this->assertNotSame($before->fingerprint, $after->fingerprint);
    }

    public function test_owner_and_key_are_part_of_the_fingerprint(): void
    {
        $json = (string) json_encode(['trusted_scripts' => ['ts' => $this->entry(), 'other' => $this->entry()]], JSON_UNESCAPED_SLASHES);
        $one = TrustedScriptManifestParser::parse('one', $json)['declarations'];
        $two = TrustedScriptManifestParser::parse('two', $json)['declarations'];

        $this->assertNotSame($one[0]->fingerprint, $two[0]->fingerprint);
        $this->assertNotSame($one[0]->fingerprint, $one[1]->fingerprint);
    }
}
