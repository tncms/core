<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Component-only interactive HTML profile (CORE-EXTENSION-TRUST S2, ADR D-6).
 *
 * Applied ONLY to the output of a registered trusted component, never to user
 * HTML, which keeps going through the unchanged `cms_html()` sanitizer. It
 * VALIDATES rather than sanitizes: any violation rejects the whole component.
 *
 *   1. Bytes: valid UTF-8, no NUL/control characters, ≤ MAX_BYTES, no slot marker.
 *   2. Strict tokenizer: every `<` opens a well-formed start or end tag of an
 *      allowed element, tags balance exactly, and there are no comments,
 *      CDATA, processing instructions, doctypes, raw-text or foreign-content
 *      elements. libxml silently repairs malformed markup; this does not.
 *   3. DOM (libxml) check of decoded attribute values: per-element allowlist,
 *      same-origin links, DOM-clobbering-safe id/name, declared data-* only.
 *      Every live form needs an action that the form authorizer accepts for
 *      its effective method (S2-R1: declared, owned, canonical route target);
 *      submitter overrides and unsafe `_method`/`_token` fields are refused.
 *      In read-only mode (preview/editor), every control must be disabled and
 *      no form may carry an action.
 *   4. The DOM must have exactly the element sequence the tokenizer saw, and
 *      the result is re-serialized and checked again for a fixpoint. The
 *      SERIALIZED form is what gets inserted, so no parser differential
 *      between the provider's raw string and the browser is reachable.
 */
final class InteractiveHtmlProfile
{
    public const MAX_BYTES = 65536;

    public const MAX_ELEMENTS = 1000;

    public const MAX_DEPTH = 32;

    private const VOID = ['br', 'hr', 'input'];

    /** Element => allowed attributes beyond the global set. */
    private const ELEMENTS = [
        'div' => [], 'span' => [], 'p' => [], 'br' => [], 'hr' => [],
        'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 's' => [], 'small' => [], 'code' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'section' => [], 'article' => [], 'header' => [], 'footer' => [],
        'a' => ['href', 'rel'],
        'form' => ['method', 'action', 'enctype', 'novalidate', 'autocomplete'],
        'fieldset' => ['disabled'],
        'legend' => [],
        'label' => ['for'],
        'input' => ['type', 'name', 'value', 'placeholder', 'required', 'disabled', 'readonly', 'checked', 'multiple',
            'min', 'max', 'step', 'minlength', 'maxlength', 'pattern', 'size', 'autocomplete', 'inputmode', 'accept', 'list'],
        'button' => ['type', 'name', 'value', 'disabled'],
        'select' => ['name', 'required', 'disabled', 'multiple', 'size', 'autocomplete'],
        'optgroup' => ['label', 'disabled'],
        'option' => ['value', 'selected', 'disabled', 'label'],
        'textarea' => ['name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly', 'minlength', 'maxlength', 'autocomplete', 'inputmode', 'wrap'],
        'output' => ['for'],
    ];

    private const GLOBAL_ATTRIBUTES = ['class', 'id', 'role', 'title', 'lang', 'dir', 'hidden', 'tabindex'];

    private const BOOLEAN = ['required', 'disabled', 'readonly', 'checked', 'multiple', 'selected', 'novalidate', 'hidden'];

    /** Elements whose content must be text only (RCDATA / option labels). */
    private const TEXT_ONLY = ['textarea', 'option'];

    private const CONTROLS = ['input', 'select', 'textarea', 'button'];

    private const INPUT_TYPES = ['text', 'email', 'tel', 'url', 'number', 'password', 'search', 'date', 'datetime-local',
        'month', 'week', 'time', 'color', 'range', 'checkbox', 'radio', 'file', 'hidden', 'submit', 'reset', 'button'];

    private const ENCTYPES = ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'];

    /** Attributes that let a control re-target or re-associate a submission (S2-R1). */
    private const SUBMITTER_OVERRIDES = ['formaction', 'formmethod', 'formenctype', 'formtarget', 'formnovalidate', 'form'];

    /** Methods a hidden `_method` field may select on a POST form (Laravel method spoofing). */
    private const SPOOFABLE = ['PUT', 'PATCH', 'DELETE'];

    /**
     * DOM clobbering (compared case-insensitively on the base name). A control's
     * `name` or `id` inside a form shadows that FORM's properties (the form
     * interface overrides built-ins), so both are checked against FORM_PROPERTIES.
     * An `id` can also create a window global, so it is additionally checked
     * against well-known globals. `name="name"` stays allowed: it only shadows
     * the form's own name string and is ubiquitous in real forms.
     */
    private const FORM_PROPERTIES = [
        // HTMLFormElement
        'action', 'method', 'submit', 'reset', 'elements', 'length', 'target', 'enctype', 'encoding',
        'acceptcharset', 'novalidate', 'autocomplete', 'requestsubmit', 'checkvalidity', 'reportvalidity', 'rel', 'rellist',
        // HTMLElement / Element
        'id', 'classname', 'classlist', 'dataset', 'style', 'attributes', 'tagname', 'localname', 'namespaceuri',
        'innerhtml', 'outerhtml', 'innertext', 'outertext', 'textcontent', 'getattribute', 'setattribute',
        'hasattribute', 'removeattribute', 'toggleattribute', 'getattributenode', 'closest', 'matches',
        'queryselector', 'queryselectorall', 'getelementsbytagname', 'getelementsbyclassname',
        'getboundingclientrect', 'focus', 'blur', 'click', 'remove', 'before', 'after', 'append', 'prepend',
        'replacewith', 'replacechildren', 'shadowroot', 'attachshadow', 'children', 'childelementcount',
        'firstelementchild', 'lastelementchild', 'parentelement', 'offsetparent',
        // Node / EventTarget
        'parentnode', 'childnodes', 'firstchild', 'lastchild', 'nextsibling', 'previoussibling', 'nodename',
        'nodetype', 'nodevalue', 'ownerdocument', 'isconnected', 'appendchild', 'removechild', 'insertbefore',
        'replacechild', 'clonenode', 'contains', 'addeventlistener', 'removeeventlistener', 'dispatchevent',
        // Object
        '__proto__', 'constructor', 'prototype', 'tostring', 'valueof', 'hasownproperty',
    ];

    private const GLOBAL_PROPERTIES = ['location', 'cookie', 'domain', 'body', 'head', 'forms', 'images', 'links',
        'scripts', 'documentelement', 'defaultview', 'getelementbyid', 'getelementsbyname', 'getelementsbytagname',
        'createelement', 'write', 'writeln', 'open', 'close', 'top', 'parent', 'self', 'frames', 'opener', 'alert',
        'eval', 'fetch', 'window', 'document', 'navigator', 'history', 'localstorage', 'sessionstorage', 'origin',
        'url', 'baseuri', 'all', 'embeds', 'plugins', 'anchors', 'applets', 'currentscript', 'activeelement'];

    private const START_TAG = '/\G<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*)\s*(\/?)>/';

    private const END_TAG = '/\G<\/([a-zA-Z][a-zA-Z0-9]*)\s*>/';

    private readonly string $origin;

    /**
     * The form authorizer returns null when the raw action is authorized for
     * the form's effective method, otherwise a reason code. Without one, every
     * live form is refused (default deny).
     *
     * @param  string  $origin  scheme://host[:port] of the current request
     * @param  list<string>  $dataAttributes  declared data-* names
     * @param  Closure(string $action, string $method): ?string|null  $formAuthorizer
     */
    public function __construct(
        string $origin,
        private readonly bool $readOnly = false,
        private readonly array $dataAttributes = [],
        private readonly ?Closure $formAuthorizer = null,
    ) {
        $this->origin = strtolower(rtrim($origin, '/'));
    }

    /**
     * Validate component output and return its normalized serialization.
     *
     * @throws InteractiveProfileViolation
     */
    public function validate(string $html): string
    {
        $out = $this->check($html);

        // Fixpoint: the normalized form must validate to itself.
        if ($this->check($out) !== $out) {
            throw new InteractiveProfileViolation('malformed: unstable');
        }

        return $out;
    }

    private function check(string $html): string
    {
        if (! mb_check_encoding($html, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) {
            throw new InteractiveProfileViolation('encoding');
        }

        $html = trim($html);

        if ($html === '') {
            throw new InteractiveProfileViolation('empty');
        }

        if (strlen($html) > self::MAX_BYTES) {
            throw new InteractiveProfileViolation('limit: bytes');
        }

        if (stripos($html, 'TNCMSSLOT') !== false) {
            throw new InteractiveProfileViolation('marker');
        }

        $sequence = $this->tokenize($html);

        return $this->checkDom($html, $sequence);
    }

    /**
     * Strict well-formedness pass. Returns the start-tag sequence.
     *
     * @return list<string>
     */
    private function tokenize(string $html): array
    {
        $sequence = [];
        $stack = [];
        $offset = 0;

        while (($lt = strpos($html, '<', $offset)) !== false) {
            if (preg_match(self::END_TAG, $html, $m, 0, $lt)) {
                $tag = strtolower($m[1]);

                if (in_array($tag, self::VOID, true) || array_pop($stack) !== $tag) {
                    throw new InteractiveProfileViolation('malformed');
                }

                $offset = $lt + strlen($m[0]);

                continue;
            }

            if (! preg_match(self::START_TAG, $html, $m, 0, $lt)) {
                throw new InteractiveProfileViolation('malformed');
            }

            $tag = strtolower($m[1]);

            if (! array_key_exists($tag, self::ELEMENTS)) {
                throw new InteractiveProfileViolation('element');
            }

            $parent = $stack === [] ? null : $stack[array_key_last($stack)];

            if (in_array($parent, self::TEXT_ONLY, true) || ($tag === 'form' && in_array('form', $stack, true))) {
                throw new InteractiveProfileViolation('structure');
            }

            $void = in_array($tag, self::VOID, true);

            if ($m[3] === '/' && ! $void) {
                throw new InteractiveProfileViolation('malformed');
            }

            $sequence[] = $tag;

            if (count($sequence) > self::MAX_ELEMENTS) {
                throw new InteractiveProfileViolation('limit: elements');
            }

            if (! $void) {
                $stack[] = $tag;

                if (count($stack) > self::MAX_DEPTH) {
                    throw new InteractiveProfileViolation('limit: depth');
                }
            }

            $offset = $lt + strlen($m[0]);
        }

        if ($stack !== []) {
            throw new InteractiveProfileViolation('malformed');
        }

        return $sequence;
    }

    /** @param list<string> $sequence */
    private function checkDom(string $html, array $sequence): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML(
                '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div data-cms-profile-root="1">'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = null;

        foreach ($dom->getElementsByTagName('div') as $candidate) {
            if ($candidate->getAttribute('data-cms-profile-root') === '1') {
                $root = $candidate;
                break;
            }
        }

        if (! $root instanceof DOMElement) {
            throw new InteractiveProfileViolation('malformed');
        }

        $seen = [];
        $this->walk($root, $seen);

        // libxml must not have moved, invented or dropped anything.
        if ($seen !== $sequence) {
            throw new InteractiveProfileViolation('malformed');
        }

        $out = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /** @param list<string> $seen */
    private function walk(DOMNode $node, array &$seen): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                $seen[] = $tag;
                $this->checkElement($child, $tag);
                $this->walk($child, $seen);

                continue;
            }

            if ($child->nodeType !== XML_TEXT_NODE) {
                throw new InteractiveProfileViolation('malformed');
            }
        }
    }

    private function checkElement(DOMElement $element, string $tag): void
    {
        if (! array_key_exists($tag, self::ELEMENTS)) {
            throw new InteractiveProfileViolation('element');
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (str_contains($value, '<') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new InteractiveProfileViolation('attribute');
            }

            if (in_array($name, self::SUBMITTER_OVERRIDES, true)) {
                throw new InteractiveProfileViolation('form: submitter override');
            }

            if (! $this->attributeAllowed($tag, $name)) {
                throw new InteractiveProfileViolation('attribute');
            }

            $this->checkValue($element, $tag, $name, $value);
        }

        if ($this->readOnly && in_array($tag, self::CONTROLS, true) && ! $this->isDisabled($element)) {
            throw new InteractiveProfileViolation('readonly');
        }

        if ($tag === 'form') {
            $this->checkForm($element);
        }
    }

    private function attributeAllowed(string $tag, string $name): bool
    {
        if (in_array($name, self::GLOBAL_ATTRIBUTES, true) || in_array($name, self::ELEMENTS[$tag], true)) {
            return true;
        }

        if (preg_match('/^aria-[a-z]+$/', $name)) {
            return true;
        }

        return str_starts_with($name, 'data-') && in_array($name, $this->dataAttributes, true);
    }

    private function checkValue(DOMElement $element, string $tag, string $name, string $value): void
    {
        $valid = match (true) {
            in_array($name, self::BOOLEAN, true) => $value === '' || strtolower($value) === $name || ($name === 'hidden' && strtolower($value) === 'until-found'),
            $name === 'class' => $this->classValid($value),
            $name === 'id' => $this->identifierValid($value, '/^[A-Za-z][A-Za-z0-9_\-:.]{0,127}$/', [...self::FORM_PROPERTIES, ...self::GLOBAL_PROPERTIES]),
            $name === 'name' => $this->identifierValid($value, '/^[A-Za-z_][A-Za-z0-9_.\-\[\]]{0,127}$/', self::FORM_PROPERTIES),
            $name === 'for', $name === 'list' => (bool) preg_match('/^[A-Za-z][A-Za-z0-9_\-:.]{0,127}( [A-Za-z][A-Za-z0-9_\-:.]{0,127})*$/', $value),
            $name === 'role' => (bool) preg_match('/^[a-z]+( [a-z]+)*$/', $value),
            $name === 'lang' => (bool) preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/', $value),
            $name === 'dir' => in_array($value, ['ltr', 'rtl', 'auto'], true),
            $name === 'tabindex' => in_array($value, ['-1', '0'], true),
            $name === 'type' => in_array(strtolower($value), $tag === 'button' ? ['submit', 'reset', 'button'] : self::INPUT_TYPES, true),
            $name === 'method' => in_array(strtolower($value), ['get', 'post'], true),
            $name === 'enctype' => in_array(strtolower($value), self::ENCTYPES, true),
            $name === 'autocomplete' => (bool) preg_match('/^[a-z0-9 \-]{1,64}$/', $value),
            $name === 'inputmode' => in_array($value, ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], true),
            $name === 'wrap' => in_array($value, ['soft', 'hard'], true),
            in_array($name, ['minlength', 'maxlength', 'size', 'rows', 'cols'], true) => (bool) preg_match('/^[0-9]{1,6}$/', $value),
            in_array($name, ['min', 'max', 'step'], true) => (bool) preg_match('/^[0-9A-Za-z:.\-]{1,32}$/', $value),
            $name === 'accept' => (bool) preg_match('/^[A-Za-z0-9.,\/*+\- ]{1,256}$/', $value),
            $name === 'rel' => (bool) preg_match('/^[a-z]+( [a-z]+)*$/', $value),
            $name === 'href' => $this->urlPath($value, true) !== null || $this->fail('url'),
            $name === 'action' => ! $this->readOnly || $this->fail('readonly'),
            default => true, // free text: value, placeholder, title, label, pattern, aria-*, data-*
        };

        if (! $valid) {
            throw new InteractiveProfileViolation(in_array($name, ['id', 'name'], true) ? 'clobber' : 'value');
        }
    }

    private function classValid(string $value): bool
    {
        foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (str_starts_with(strtolower($token), 'tncms-slot')) {
                throw new InteractiveProfileViolation('marker');
            }

            if (! preg_match('/^[A-Za-z0-9_\-:]{1,64}$/', $token)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $denied */
    private function identifierValid(string $value, string $grammar, array $denied): bool
    {
        if (! preg_match($grammar, $value)) {
            return false;
        }

        $base = strtolower((string) strstr($value.'[', '[', true));

        return ! in_array($base, $denied, true);
    }

    /**
     * Effective method, method-override and CSRF-field rules, then target
     * authorization (S2-R1). Attribute values were validated just before.
     */
    private function checkForm(DOMElement $form): void
    {
        $method = strtoupper($form->getAttribute('method') ?: 'GET');
        // Laravel reads `_method` only from a parsed body: never from text/plain.
        $parsed = strtolower($form->getAttribute('enctype')) !== 'text/plain';
        $override = null;

        foreach ($form->getElementsByTagName('*') as $control) {
            $name = $control->getAttribute('name');
            $base = strtolower((string) strstr($name.'[', '[', true));

            if ($base === '_method') {
                $hidden = strtolower($control->tagName) === 'input' && strtolower($control->getAttribute('type')) === 'hidden';
                $value = $control->getAttribute('value');

                // A field the browser would not send leaves the real method at POST.
                $sent = $parsed && ($this->readOnly || ! $this->isDisabled($control));

                if ($method !== 'POST' || ! $hidden || ! $sent || $name !== '_method' || $override !== null || ! in_array($value, self::SPOOFABLE, true)) {
                    throw new InteractiveProfileViolation('form: method override');
                }

                $override = $value;
            }

            // A CSRF token in a GET form would leak into URLs, logs and referrers.
            if ($base === '_token' && $method === 'GET') {
                throw new InteractiveProfileViolation('form: csrf in get');
            }
        }

        if ($this->readOnly) {
            return; // an action was already refused; the form is inert.
        }

        if (! $form->hasAttribute('action')) {
            throw new InteractiveProfileViolation('form: action missing');
        }

        $reason = $this->formAuthorizer === null
            ? 'form target: undeclared'
            : ($this->formAuthorizer)($form->getAttribute('action'), $override ?? $method);

        if ($reason !== null) {
            throw new InteractiveProfileViolation($reason);
        }
    }

    /**
     * Path of a same-origin URL (`/path…` or `{origin}/path…`), or null.
     * `#fragment` is accepted for links only.
     */
    private function urlPath(string $url, bool $allowFragment): ?string
    {
        if ($url === '' || ! preg_match('/^[\x21-\x7E]+$/', $url) || str_contains($url, '\\')) {
            return null;
        }

        if ($allowFragment && str_starts_with($url, '#')) {
            return '#';
        }

        if (str_starts_with(strtolower($url), $this->origin)) {
            $rest = substr($url, strlen($this->origin));

            if ($rest !== '' && ! in_array($rest[0], ['/', '?', '#'], true)) {
                return null;
            }

            $url = $rest === '' || $rest[0] !== '/' ? '/'.$rest : $rest;
        }

        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        $path = (string) (parse_url('http://h'.$url, PHP_URL_PATH) ?? '/');

        if (preg_match('#(^|/)\.{1,2}(/|$)|%2e|%2f|%5c|%00#i', $path)) {
            return null;
        }

        return $path;
    }

    private function isDisabled(DOMElement $control): bool
    {
        if ($control->hasAttribute('disabled')) {
            return true;
        }

        $child = $control;

        for ($node = $control->parentNode; $node instanceof DOMElement; $child = $node, $node = $node->parentNode) {
            if (strtolower($node->tagName) !== 'fieldset' || ! $node->hasAttribute('disabled')) {
                continue;
            }

            // A disabled fieldset does not disable its first <legend>'s contents.
            $legend = null;
            foreach ($node->childNodes as $candidate) {
                if ($candidate instanceof DOMElement && strtolower($candidate->tagName) === 'legend') {
                    $legend = $candidate;
                    break;
                }
            }

            if ($legend === null || $child !== $legend) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $reason): never
    {
        throw new InteractiveProfileViolation($reason);
    }
}
