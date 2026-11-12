<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use TheNguyen\CMS\Support\Html\AccessibleAttributePolicy;
use TheNguyen\CMS\Support\Html\SafeHtmlId;

/**
 * Minimal, dependency-free HTML sanitizer for rich-editor (TinyMCE) content.
 *
 * Uses DOMDocument to parse the input, then walks the tree keeping only an
 * allow-list of tags and attributes. Dangerous elements (script, style,
 * iframe, …) are dropped entirely; unknown-but-harmless wrappers are unwrapped
 * so their text survives. URLs are scheme-checked to block javascript:/data:
 * vectors. External `target="_blank"` links get `rel="noopener noreferrer"`.
 *
 * A bounded accessibility contract (CORE-HTML-SANITIZER-A11Y-R1) additionally keeps an `id` in the Core-reserved
 * authored-content namespace `cms-content-*` (`SafeHtmlId`; first occurrence wins) on any kept element and the
 * three `AccessibleAttributePolicy` ARIA attributes on their element sets; `aria-labelledby` must resolve to
 * ids kept in the same fragment. Every other id is removed, so content can never claim a runtime id. Invalid
 * values lose only the attribute.
 *
 * This is intentionally simple — no external Composer package required.
 */
class HtmlSanitizer
{
    /** Tags kept in the output. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'a',
        'blockquote',
        'pre', 'code',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'img',
        'figure', 'figcaption',
        'hr',
        'span', 'div',
        // Semantic sectioning + disclosure elements. Safe (no scripting) and
        // required so server-rendered structured content — e.g. the Page Builder
        // library's <section> wrappers, quote <footer>, and accordion
        // <details>/<summary> — survives output sanitization.
        'section', 'article', 'header', 'footer', 'nav', 'aside', 'main',
        'details', 'summary',
    ];

    /** Tags removed together with their entire subtree. */
    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'textarea', 'button', 'select', 'option', 'link', 'meta', 'base',
        'svg', 'math', 'noscript', 'head', 'title', 'applet', 'frame', 'frameset',
    ];

    /** Attributes allowed on every kept tag. */
    private const GLOBAL_ATTRS = ['class'];

    /** Attributes allowed per specific tag (in addition to GLOBAL_ATTRS). */
    private const TAG_ATTRS = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    public function sanitize(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // Wrap so we have a single, identifiable root and a forced UTF-8 charset.
        $wrapped = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            . '<div data-cms-root="1">' . $html . '</div>';

        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $this->findRoot($dom);

        if ($root === null) {
            return '';
        }

        $this->cleanChildren($root);
        $this->resolveReferences($dom, $root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function findRoot(DOMDocument $dom): ?DOMElement
    {
        $nodes = (new DOMXPath($dom))->query('//div[@data-cms-root="1"]');

        $root = $nodes?->item(0);

        return $root instanceof DOMElement ? $root : null;
    }

    private function cleanChildren(DOMNode $node): void
    {
        // Snapshot the live NodeList so removals/unwraps don't break iteration.
        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->cleanNode($child);
        }
    }

    private function cleanNode(DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            return;
        }

        if ($node instanceof DOMComment) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (! $node instanceof DOMElement) {
            // Processing instructions, CDATA, etc. — drop.
            $node->parentNode?->removeChild($node);

            return;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DANGEROUS_TAGS, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // Unknown but not dangerous: keep the text, drop the wrapper.
            $this->cleanChildren($node);
            $this->unwrap($node);

            return;
        }

        $this->cleanAttributes($node, $tag);
        $this->cleanChildren($node);
    }

    private function cleanAttributes(DOMElement $node, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? []);

        foreach (iterator_to_array($node->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);

            // Never keep event handlers / styles / anything off the allow-list.
            if (! in_array($name, $allowed, true) && ! $this->isValidAccessibleAttribute($tag, $name, $attr->value)) {
                // By node, not by name: removeAttribute() resolves `xml:*` / `xmlns*` names through namespace
                // lookup and silently misses the null-namespace attribute the HTML parser produced.
                $node->removeAttributeNode($attr);
            }
        }

        if ($tag === 'a' && $node->hasAttribute('href')) {
            if (! $this->isSafeUrl($node->getAttribute('href'), allowDataImage: false)) {
                $node->removeAttribute('href');
            }
        }

        if ($tag === 'img') {
            if (! $node->hasAttribute('src')
                || ! $this->isSafeUrl($node->getAttribute('src'), allowDataImage: true)) {
                // An image with an unsafe/missing src is useless and risky — drop it.
                $node->parentNode?->removeChild($node);

                return;
            }
        }

        // External links opening in a new tab must not leak the opener.
        if ($tag === 'a' && strtolower($node->getAttribute('target')) === '_blank') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** The additive R1 attributes: `id` on any kept element, bounded ARIA on its element set. */
    private function isValidAccessibleAttribute(string $tag, string $name, string $value): bool
    {
        return match (true) {
            $name === 'id' => SafeHtmlId::isValid($value),
            ! AccessibleAttributePolicy::allows($tag, $name) => false,
            $name === 'aria-label' => AccessibleAttributePolicy::isValidLabel($value),
            $name === 'aria-current' => AccessibleAttributePolicy::isValidCurrent($value),
            $name === 'aria-labelledby' => AccessibleAttributePolicy::idRefTokens($value) !== null,
            default => false,
        };
    }

    /**
     * Post-pass over the cleaned fragment: the first `id` in document order wins, later duplicates lose the
     * attribute; then every `aria-labelledby` token must name a surviving id, otherwise it is removed.
     */
    private function resolveReferences(DOMDocument $dom, DOMElement $root): void
    {
        $xpath = new DOMXPath($dom);
        $ids = [];

        foreach (iterator_to_array($xpath->query('.//*[@id]', $root) ?: []) as $element) {
            $id = $element->getAttribute('id');

            if (isset($ids[$id])) {
                $element->removeAttribute('id');
            } else {
                $ids[$id] = true;
            }
        }

        foreach (iterator_to_array($xpath->query('.//*[@aria-labelledby]', $root) ?: []) as $element) {
            foreach (explode(' ', $element->getAttribute('aria-labelledby')) as $token) {
                if (! isset($ids[$token])) {
                    $element->removeAttribute('aria-labelledby');
                    break;
                }
            }
        }
    }

    private function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;

        if ($parent === null) {
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    /**
     * Allow relative/anchor/protocol-relative URLs and the http(s)/mailto/tel
     * schemes. Block javascript:, vbscript:, and non-image data: URIs. Control
     * characters are stripped before the scheme test to defeat obfuscation
     * (e.g. "java\nscript:").
     */
    private function isSafeUrl(string $url, bool $allowDataImage): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        $probe = preg_replace('/[\x00-\x20]+/', '', $url) ?? $url;

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $probe, $matches) === 1) {
            $scheme = strtolower($matches[1]);

            if (in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
                return true;
            }

            if ($allowDataImage && $scheme === 'data') {
                return preg_match('#^data:image/(png|jpe?g|gif|webp|bmp);base64,#i', $probe) === 1;
            }

            return false;
        }

        // No scheme → relative path, anchor, or protocol-relative URL.
        return true;
    }
}
