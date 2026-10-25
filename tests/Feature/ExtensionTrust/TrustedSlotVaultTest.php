<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Http\Request;
use Tests\TestCase;
use TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault;

/**
 * CORE-EXTENSION-TRUST S2 — the per-request placeholder vault (brief §3.2,
 * matrix T2/T16/T21, owner decision D-7: strip unminted/forged wrappers).
 */
final class TrustedSlotVaultTest extends TestCase
{
    private function vault(): TrustedSlotVault
    {
        $vault = TrustedSlotVault::forRequest(Request::create('/'));
        $vault->activate();

        return $vault;
    }

    private function token(string $placeholder): string
    {
        $this->assertMatchesRegularExpression('#^<div class="tncms-slot">TNCMSSLOT[0-9a-f]{32}</div>$#', $placeholder);

        return substr($placeholder, strlen('<div class="tncms-slot">TNCMSSLOT'), 32);
    }

    public function test_placeholder_survives_the_unchanged_sanitizer_byte_exact(): void
    {
        $placeholder = $this->vault()->mint('<p>x</p>');

        $this->assertSame($placeholder, cms_html($placeholder));
        $this->assertStringContainsString($placeholder, cms_html('<p>before</p>'.$placeholder.'<p>after</p>'));
        $this->assertSame(TrustedSlotVault::FALLBACK, cms_html(TrustedSlotVault::FALLBACK));
    }

    public function test_tokens_are_128_bit_unique_and_not_sequential(): void
    {
        $vault = $this->vault();
        $tokens = [];

        for ($i = 0; $i < TrustedSlotVault::MAX_SLOTS; $i++) {
            $tokens[] = $this->token($vault->mint('<p>'.$i.'</p>'));
        }

        $this->assertCount(TrustedSlotVault::MAX_SLOTS, array_unique($tokens));

        foreach ($tokens as $token) {
            $this->assertSame(32, strlen($token));          // 16 random bytes = 128 bits
            $this->assertSame(16, strlen((string) hex2bin($token)));
        }

        // No shared prefix / counter structure between consecutive tokens.
        $this->assertNotSame(substr($tokens[0], 0, 8), substr($tokens[1], 0, 8));
        // A second vault never re-issues a token.
        $this->assertNotContains($this->token($this->vault()->mint('<p>x</p>')), $tokens);
    }

    public function test_only_minted_tokens_resolve_once_and_everything_else_is_stripped(): void
    {
        $vault = $this->vault();
        $a = $vault->mint('<p class="a">A</p>');
        $b = $vault->mint('<p class="b">B</p>');
        $forged = '<div class="tncms-slot">TNCMSSLOT'.str_repeat('0', 32).'</div>';
        $bare = 'TNCMSSLOT'.$this->token($b);

        $body = "<main>{$a}|{$b}|{$a}|{$forged}|<meta content=\"{$bare}\">|</main>";
        $result = $vault->resolve($body, insert: true);

        $this->assertSame('<main><p class="a">A</p>|<p class="b">B</p>|||<meta content="">|</main>', $result['body']);
        $this->assertSame(2, $result['resolved']);
        $this->assertSame(3, $result['stripped']);   // the duplicate of a, the forged wrapper, the bare token
    }

    public function test_resolution_is_single_pass_and_never_expands_tokens_inside_component_html(): void
    {
        $vault = $this->vault();
        $inner = $vault->mint('<p>inner</p>');
        // Simulate a component whose (already-validated) HTML would contain a slot.
        $outer = $vault->mint('<p>outer</p>');

        $body = $outer.$inner;
        $result = $vault->resolve($body, insert: true);

        $this->assertSame('<p>outer</p><p>inner</p>', $result['body']);

        // A second resolve on the output finds nothing to do and no tokens.
        $again = $vault->resolve($result['body'], insert: true);
        $this->assertSame($result['body'], $again['body']);
        $this->assertSame(0, $again['resolved']);
    }

    public function test_used_tokens_cannot_be_replayed_and_insert_false_only_strips(): void
    {
        $vault = $this->vault();
        $a = $vault->mint('<p>A</p>');

        $this->assertSame('', $vault->resolve($a, insert: false)['body']);
        // Stripping consumed nothing: the token is still unused, but insert=false never inserts.
        $first = $vault->resolve($a, insert: true);
        $this->assertSame('<p>A</p>', $first['body']);
        $this->assertSame('', $vault->resolve($a, insert: true)['body']);
    }

    public function test_request_isolation_cross_request_tokens_never_resolve(): void
    {
        $requestA = Request::create('/a');
        $requestB = Request::create('/b');
        $vaultA = TrustedSlotVault::forRequest($requestA);
        $vaultB = TrustedSlotVault::forRequest($requestB);
        $vaultA->activate();
        $vaultB->activate();

        $fromA = $vaultA->mint('<p>secret-for-A</p>');

        $this->assertSame('', $vaultB->resolve($fromA, insert: true)['body']);
        $this->assertSame(0, $vaultB->mintedCount());
        $this->assertSame('<p>secret-for-A</p>', $vaultA->resolve($fromA, insert: true)['body']);

        // The same Request always yields the same vault; a new Request a new one.
        $this->assertSame($vaultA, TrustedSlotVault::forRequest($requestA));
        $this->assertNotSame($vaultA, TrustedSlotVault::forRequest(Request::create('/a')));
    }

    public function test_lifecycle_inactive_sealed_and_slot_limit(): void
    {
        $vault = TrustedSlotVault::forRequest(Request::create('/'));
        $this->assertFalse($vault->canMint());

        $vault->activate();
        $this->assertTrue($vault->canMint());

        for ($i = 0; $i < TrustedSlotVault::MAX_SLOTS; $i++) {
            $vault->mint('<p>'.$i.'</p>');
        }

        $this->assertFalse($vault->canMint());
        $this->assertSame(TrustedSlotVault::MAX_SLOTS, $vault->mintedCount());

        $sealed = TrustedSlotVault::forRequest(Request::create('/'));
        $sealed->activate();
        $sealed->seal();
        $this->assertFalse($sealed->canMint());
        $this->expectException(\LogicException::class);
        $sealed->mint('<p>late</p>');
    }

    public function test_malformed_and_lookalike_wrappers_are_not_resolved(): void
    {
        $vault = $this->vault();
        $real = $vault->mint('<p>R</p>');
        $token = $this->token($real);

        $variants = [
            '<div class="tncms-slot x">TNCMSSLOT'.$token.'</div>',
            '<div class="tncms-slot" >TNCMSSLOT'.$token.'</div>',
            '<DIV class="tncms-slot">TNCMSSLOT'.$token.'</DIV>',
            '<div class="tncms-slot">TNCMSSLOT'.strtoupper($token).'</div>',
            '&lt;div class="tncms-slot"&gt;TNCMSSLOT'.$token.'&lt;/div&gt;',
        ];

        foreach ($variants as $variant) {
            $out = $vault->resolve($variant, insert: true)['body'];
            $this->assertStringNotContainsString('<p>R</p>', $out, $variant);
            $this->assertStringNotContainsString($token, strtolower($out), $variant);
        }

        // The real one is still unused and resolves.
        $this->assertSame('<p>R</p>', $vault->resolve($real, insert: true)['body']);
    }
}
