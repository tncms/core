<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

/**
 * Minimal, dependency-free SVG sanitizer.
 *
 * SVG is an XML document that browsers treat as active content: it can carry
 * <script>, event handlers (onload=...), <foreignObject> with embedded HTML,
 * and javascript:/data: URLs. We never trust an uploaded SVG, so before such a
 * file is written to disk we parse it and strip everything that can execute.
 *
 * This complements (and mirrors the philosophy of) {@see HtmlSanitizer}: an
 * allow-list of attributes is impractical for SVG's huge surface, so we use a
 * focused deny-list of the constructs that actually lead to script execution.
 */
class SvgSanitizer
{
    /**
     * Elements that can execute script or embed foreign (HTML) content.
     */
    private const DANGEROUS_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object',
        'audio', 'video', 'handler', 'listener', 'set', 'animate',
        'animatetransform', 'animatemotion',
    ];

    /**
     * URL-bearing attributes whose value must be scheme-checked.
     */
    private const URL_ATTRS = ['href', 'xlink:href', 'src', 'from', 'to', 'values', 'begin'];

    /**
     * Sanitize raw SVG markup. Returns null when the input is not parseable as
     * SVG so the caller can reject the upload (fail-safe deny).
     */
    public function sanitize(string $svg): ?string
    {
        $svg = trim($svg);

        if ($svg === '' || stripos($svg, '<svg') === false) {
            return null;
        }

        // Strip any DOCTYPE/ENTITY declarations up front to neutralize XXE /
        // billion-laughs before the parser ever sees them.
        $svg = preg_replace('/<!DOCTYPE.*?>/is', '', $svg) ?? $svg;
        $svg = preg_replace('/<!ENTITY.*?>/is', '', $svg) ?? $svg;

        $previousErrors = libxml_use_internal_errors(true);
        $previousEntityLoader = null;

        // Disable external entity loading where the runtime still honors it.
        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        try {
            $dom = new \DOMDocument();
            $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOENT);

            if ($loaded === false) {
                return null;
            }

            $this->scrub($dom->documentElement);

            $output = $dom->saveXML($dom->documentElement);

            return $output !== false ? $output : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);

            if ($previousEntityLoader !== null && function_exists('libxml_disable_entity_loader')) {
                libxml_disable_entity_loader($previousEntityLoader);
            }
        }
    }

    /**
     * Sanitize an SVG file in place. Returns true on success, false when the
     * file could not be read or did not contain safe SVG (caller should reject).
     */
    public function sanitizeFile(string $path): bool
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return false;
        }

        $clean = $this->sanitize($raw);

        if ($clean === null) {
            return false;
        }

        return @file_put_contents($path, $clean) !== false;
    }

    /**
     * Recursively remove dangerous elements and attributes from a node.
     */
    private function scrub(\DOMNode $node): void
    {
        // Walk a static copy of the child list since we mutate during the loop.
        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            if ($child instanceof \DOMElement) {
                if (in_array(strtolower($child->localName), self::DANGEROUS_ELEMENTS, true)) {
                    $node->removeChild($child);

                    continue;
                }

                $this->scrubAttributes($child);
                $this->scrub($child);
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Strip event handlers and unsafe URL schemes from an element.
     */
    private function scrubAttributes(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = $attr->nodeValue ?? '';

            // Any on* handler (onload, onclick, onmouseover, ...).
            if (str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attr);

                continue;
            }

            if (in_array($name, self::URL_ATTRS, true) && ! $this->isSafeUrl($value)) {
                $element->removeAttributeNode($attr);
            }
        }
    }

    /**
     * A URL value is safe when it carries no executable scheme. Strip control
     * characters first so "java\0script:" style obfuscation cannot slip through.
     */
    private function isSafeUrl(string $value): bool
    {
        $normalized = strtolower(trim(preg_replace('/[\x00-\x20]/', '', $value) ?? ''));

        if ($normalized === '') {
            return true;
        }

        foreach (['javascript:', 'vbscript:', 'data:text/html', 'data:application', 'data:image/svg'] as $bad) {
            if (str_contains($normalized, $bad)) {
                return false;
            }
        }

        return true;
    }
}
