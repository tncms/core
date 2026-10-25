<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

use Illuminate\Http\Request;
use LogicException;

/**
 * The per-request placeholder vault (CORE-EXTENSION-TRUST S2, ADR D-C/D-D/D-7).
 *
 * Lives on the Request object itself (an attribute), so it can never leak into
 * another request — not across sequential requests in a persistent worker, not
 * across sessions — and it is never persisted anywhere.
 *
 * A minted placeholder is `<div class="tncms-slot">TNCMSSLOT{32 hex}</div>`:
 * 128 random bits from the CSPRNG, minted while the page renders (after any
 * stored content was loaded), so authored content cannot predict it. The
 * wrapper is made only of a `class` attribute and alphanumerics, so the
 * unchanged `cms_html()` sanitizer passes it through byte-for-byte.
 *
 * {@see resolve()} makes ONE linear pass over the final body:
 *   - an exact wrapper whose token was minted here and not yet used → the
 *     component HTML (when inserting), once;
 *   - every other wrapper or bare token — forged, copied from another request,
 *     replayed, duplicated, case-altered — → removed (D-7).
 * PCRE never re-scans replacement text, so component HTML is never expanded.
 */
final class TrustedSlotVault
{
    public const ATTRIBUTE = 'cms.trusted_slots';

    /** What a failed render returns: no token, no reason, sanitizer-safe. */
    public const FALLBACK = '<div class="tncms-slot tncms-slot--unavailable"></div>';

    public const MAX_SLOTS = 32;

    private const PREFIX = '<div class="tncms-slot">TNCMSSLOT';

    private const SUFFIX = '</div>';

    /** Exact (case-sensitive) wrapper, or any bare token in any case. */
    private const PATTERN = '#<div class="tncms-slot">TNCMSSLOT([0-9a-f]{32})</div>|(?i:TNCMSSLOT[0-9a-f]{32})#';

    /** @var array<string, string> token => validated component HTML */
    private array $entries = [];

    /** @var array<string, true> */
    private array $used = [];

    private bool $active = false;

    private bool $sealed = false;

    private int $depth = 0;

    private function __construct() {}

    public static function forRequest(Request $request): self
    {
        $vault = $request->attributes->get(self::ATTRIBUTE);

        if (! $vault instanceof self) {
            $vault = new self;
            $request->attributes->set(self::ATTRIBUTE, $vault);
        }

        return $vault;
    }

    /** Called by the `cms.trusted_slots` middleware: this route resolves slots. */
    public function activate(): void
    {
        $this->active = true;
    }

    /** Called once the response is built: nothing may be minted afterwards. */
    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isActive(): bool
    {
        return $this->active && ! $this->sealed;
    }

    public function isFull(): bool
    {
        return count($this->entries) >= self::MAX_SLOTS;
    }

    public function canMint(): bool
    {
        return $this->isActive() && ! $this->isFull();
    }

    public function mintedCount(): int
    {
        return count($this->entries);
    }

    /** Store validated HTML and return its placeholder. */
    public function mint(string $html): string
    {
        if (! $this->canMint()) {
            throw new LogicException('Trusted slot vault cannot mint on this request.');
        }

        do {
            $token = bin2hex(random_bytes(16));
        } while (isset($this->entries[$token]));

        $this->entries[$token] = $html;

        return self::PREFIX.$token.self::SUFFIX;
    }

    /** Nesting guard: a component may not render another component. */
    public function enter(): bool
    {
        if ($this->depth > 0) {
            return false;
        }

        $this->depth++;

        return true;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    /**
     * @return array{body: string, resolved: int, stripped: int}
     */
    public function resolve(string $body, bool $insert): array
    {
        $resolved = 0;
        $stripped = 0;

        $out = preg_replace_callback(self::PATTERN, function (array $match) use ($insert, &$resolved, &$stripped): string {
            $token = $match[1] ?? '';

            if ($insert && $token !== '' && isset($this->entries[$token]) && ! isset($this->used[$token])) {
                $this->used[$token] = true;
                $resolved++;

                return $this->entries[$token];
            }

            $stripped++;

            return '';
        }, $body);

        if (! is_string($out)) {
            // PCRE failure: never emit a body whose slots could not be processed.
            return ['body' => (string) preg_replace('#(?i:TNCMSSLOT)[0-9a-f]{32}#i', '', $body), 'resolved' => 0, 'stripped' => -1];
        }

        return ['body' => $out, 'resolved' => $resolved, 'stripped' => $stripped];
    }
}
