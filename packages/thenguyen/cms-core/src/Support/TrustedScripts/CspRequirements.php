<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedScripts;

/**
 * Aggregated CSP sources required by a set of trusted external scripts
 * (CORE-EXTENSION-TRUST S1, ADR D-3). REPORTING ONLY: Core never turns this
 * into a Content-Security-Policy header. Operators who run a CSP at the edge
 * use it to see exactly which origins the active, approved extensions need.
 */
final class CspRequirements
{
    /**
     * @param  array<string, list<string>>  $directives  Directive => sorted unique origins; directives sorted.
     */
    private function __construct(public readonly array $directives) {}

    /** @param  iterable<TrustedScriptDeclaration>  $declarations */
    public static function fromDeclarations(iterable $declarations): self
    {
        $merged = [];

        foreach ($declarations as $declaration) {
            foreach ($declaration->csp as $directive => $origins) {
                foreach ($origins as $origin) {
                    $merged[$directive][$origin] = true;
                }
            }
        }

        $out = [];

        foreach ($merged as $directive => $origins) {
            $list = array_keys($origins);
            sort($list);
            $out[$directive] = $list;
        }

        ksort($out);

        return new self($out);
    }

    public function isEmpty(): bool
    {
        return $this->directives === [];
    }

    /** @return list<string> */
    public function sourcesFor(string $directive): array
    {
        return $this->directives[$directive] ?? [];
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->directives;
    }
}
