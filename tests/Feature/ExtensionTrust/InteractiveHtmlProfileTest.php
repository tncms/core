<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Support\TrustedComponents\InteractiveHtmlProfile;
use TheNguyen\CMS\Support\TrustedComponents\InteractiveProfileViolation;

/**
 * CORE-EXTENSION-TRUST S2 — the component-only interactive HTML profile
 * (brief §3.3, matrix T3/T4/T19/T20/T21, owner decision D-6: reject the whole
 * output, never repair).
 */
final class InteractiveHtmlProfileTest extends TestCase
{
    private const ORIGIN = 'https://cms.example.test';

    private function profile(bool $readOnly = false, array $data = [], ?\Closure $routes = null): InteractiveHtmlProfile
    {
        return new InteractiveHtmlProfile(
            origin: self::ORIGIN,
            readOnly: $readOnly,
            dataAttributes: $data,
            // S2-R1: a stub authorizer accepting exact (action, method) pairs. The
            // real route/owner/canonical rules are tested end to end in
            // TrustedComponentFormTargetTest against TrustedFormTargetAuthorizer.
            formAuthorizer: $routes ?? fn (string $action, string $method): ?string => in_array([$action, $method], [
                ['/contact/submit', 'POST'], ['/search', 'GET'], ['https://cms.example.test/contact/submit', 'POST'],
            ], true) ? null : 'form target: undeclared',
        );
    }

    private function reject(string $html, string $reasonPrefix, ?InteractiveHtmlProfile $profile = null): void
    {
        try {
            ($profile ?? $this->profile())->validate($html);
            $this->fail('accepted: '.$html);
        } catch (InteractiveProfileViolation $e) {
            $this->assertStringStartsWith($reasonPrefix, $e->reason, $html);
            // The reason is a fixed code: it never echoes the offending markup.
            $this->assertStringNotContainsString('<', $e->reason);
        }
    }

    public function test_accessible_multipart_form_with_csrf_is_accepted_and_normalized(): void
    {
        $html = '<section class="wrap"><form method="post" action="/contact/submit" enctype="multipart/form-data" novalidate>'
            .'<input type="hidden" name="_token" value="abc123">'
            .'<fieldset><legend>Contact</legend>'
            .'<label for="c-name">Name</label><input type="text" id="c-name" name="name" required maxlength="120" autocomplete="name" aria-describedby="c-hint">'
            .'<small id="c-hint">Your full name</small>'
            .'<label for="c-file">File</label><input type="file" id="c-file" name="attachment" accept=".pdf,image/png">'
            .'<label for="c-msg">Message</label><textarea id="c-msg" name="message" rows="4">Hello &amp; welcome</textarea>'
            .'<select name="topic" aria-label="Topic"><optgroup label="General"><option value="a" selected>A &amp; B</option></optgroup></select>'
            .'<input type="checkbox" id="c-ok" name="consent" value="1"><label for="c-ok">I agree</label>'
            .'</fieldset><button type="submit">Send</button></form>'
            .'<p role="status"><a href="/contact">Back</a> <strong>Note</strong></p></section>';

        $out = $this->profile()->validate($html);

        foreach (['<form method="post" action="/contact/submit" enctype="multipart/form-data" novalidate>', 'name="_token" value="abc123"', '<textarea id="c-msg" name="message" rows="4">Hello &amp; welcome</textarea>', '<button type="submit">Send</button>', '<label for="c-name">Name</label>', 'aria-describedby="c-hint"'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }

        // Stable: validating the normalized output again yields the same bytes.
        $this->assertSame($out, $this->profile()->validate($out));
    }

    public function test_same_origin_absolute_action_and_declared_data_attributes_are_accepted(): void
    {
        // S2-R1: the query string was dropped from this fixture; queries are now
        // refused as non-canonical by the real authorizer.
        $out = $this->profile(data: ['data-sitekey'])->validate(
            '<form method="post" action="https://cms.example.test/contact/submit"><div class="cf-turnstile" data-sitekey="0x4AAA"></div></form>'
        );

        $this->assertStringContainsString('data-sitekey="0x4AAA"', $out);
        $this->assertStringContainsString('action="https://cms.example.test/contact/submit"', $out);
    }

    public function test_without_a_form_authorizer_every_live_form_is_refused(): void
    {
        $bare = new InteractiveHtmlProfile(origin: self::ORIGIN);

        $this->reject('<form method="post" action="/contact/submit"></form>', 'form target: undeclared', $bare);
        $this->assertSame('<p>ok</p>', $bare->validate('<p>ok</p>'));
    }

    public function test_the_authorizer_gets_the_raw_action_and_the_effective_method(): void
    {
        $seen = [];
        $profile = $this->profile(routes: function (string $action, string $method) use (&$seen): ?string {
            $seen[] = [$action, $method];

            return null;
        });

        $profile->validate('<form method="post" action="/a?b#c"><input type="hidden" name="_method" value="PATCH"></form><form action="/s"></form>');

        // Twice per form: the validation pass and the fixpoint pass.
        $this->assertSame([['/a?b#c', 'PATCH'], ['/s', 'GET']], array_values(array_unique($seen, SORT_REGULAR)));
    }

    /** @return array<string, array{string, string}> */
    public static function dangerous(): array
    {
        return [
            'script' => ['<p>x</p><script>alert(1)</script>', 'element'],
            'style element' => ['<style>p{}</style>', 'element'],
            'iframe' => ['<iframe src="/x"></iframe>', 'element'],
            'svg' => ['<svg><a href="/x">x</a></svg>', 'element'],
            'math' => ['<math></math>', 'element'],
            'object' => ['<object data="/x"></object>', 'element'],
            'embed' => ['<embed src="/x">', 'element'],
            'img' => ['<img src="/x.png" alt="">', 'element'],
            'base' => ['<base href="https://evil.test/">', 'element'],
            'meta' => ['<meta http-equiv="refresh" content="0">', 'element'],
            'link' => ['<link rel="stylesheet" href="/x.css">', 'element'],
            'template' => ['<template><p>x</p></template>', 'element'],
            'noscript' => ['<noscript><p>x</p></noscript>', 'element'],
            'unknown element' => ['<marquee>x</marquee>', 'element'],
            'onclick' => ['<button type="button" onclick="alert(1)">x</button>', 'attribute'],
            'onerror uppercase' => ['<div ONERROR="alert(1)">x</div>', 'attribute'],
            'style attribute' => ['<div style="background:url(javascript:alert(1))">x</div>', 'attribute'],
            'formaction' => ['<form method="post" action="/contact/submit"><button type="submit" formaction="https://evil.test/">x</button></form>', 'form: submitter override'],
            'form attribute' => ['<input type="text" name="q" form="login-form">', 'form: submitter override'],
            'srcdoc' => ['<div srcdoc="x">x</div>', 'attribute'],
            'undeclared data attribute' => ['<div data-bs-toggle="x">x</div>', 'attribute'],
            'reserved data attribute' => ['<div data-tncms-slot="x">x</div>', 'attribute'],
            'autofocus' => ['<input type="text" name="q" autofocus>', 'attribute'],
            'input image' => ['<form method="post" action="/contact/submit"><input type="image" src="/x.png"></form>', 'value'],
            'javascript action' => ['<form method="post" action="javascript:alert(1)"></form>', 'form target'],
            'javascript action entity' => ['<form method="post" action="jav&#x61;script:alert(1)"></form>', 'form target'],
            'data action' => ['<form method="post" action="data:text/html,x"></form>', 'form target'],
            'scheme-relative action' => ['<form method="post" action="//evil.test/contact/submit"></form>', 'form target'],
            'backslash action' => ['<form method="post" action="/\\evil.test/x"></form>', 'form target'],
            'foreign origin action' => ['<form method="post" action="https://evil.test/contact/submit"></form>', 'form target'],
            'http downgrade action' => ['<form method="post" action="http://cms.example.test/contact/submit"></form>', 'form target'],
            'other port action' => ['<form method="post" action="https://cms.example.test:8443/contact/submit"></form>', 'form target'],
            'userinfo action' => ['<form method="post" action="https://u:p@cms.example.test/contact/submit"></form>', 'form target'],
            'relative action without slash' => ['<form method="post" action="contact/submit"></form>', 'form target'],
            'empty action' => ['<form method="post" action=""></form>', 'form target'],
            'unknown route action' => ['<form method="post" action="/no/such/route"></form>', 'form target'],
            'method mismatch route' => ['<form method="post" action="/search"></form>', 'form target'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', 'url'],
            'foreign href' => ['<a href="https://evil.test/">x</a>', 'url'],
            'bad method' => ['<form method="dialog" action="/contact/submit"></form>', 'value'],
            'bad enctype' => ['<form method="post" action="/contact/submit" enctype="text/xml"></form>', 'value'],
            'clobber id' => ['<div id="location">x</div>', 'clobber'],
            'clobber name submit' => ['<form method="post" action="/contact/submit"><input type="text" name="submit"></form>', 'clobber'],
            'clobber name action' => ['<form method="post" action="/contact/submit"><input type="hidden" name="action" value="x"></form>', 'clobber'],
            'clobber proto' => ['<input type="text" name="__proto__">', 'clobber'],
            'clobber classList' => ['<input type="text" name="classList">', 'clobber'],
            'clobber dataset' => ['<input type="text" name="dataset">', 'clobber'],
            'clobber firstChild id' => ['<div id="firstChild">x</div>', 'clobber'],
            'clobber elements array name' => ['<input type="text" name="elements[]">', 'clobber'],
            'bad id grammar' => ['<div id="a b">x</div>', 'clobber'],
            'form name' => ['<form name="x" method="post" action="/contact/submit"></form>', 'attribute'],
            'textarea with element' => ['<textarea name="m"><b>x</b></textarea>', 'structure'],
            'option with element' => ['<select name="s"><option value="1"><b>x</b></option></select>', 'structure'],
            'comment' => ['<p>x</p><!-- c -->', 'malformed'],
            'conditional comment' => ['<!--[if IE]><p>x</p><![endif]-->', 'malformed'],
            'cdata' => ['<p><![CDATA[x]]></p>', 'malformed'],
            'processing instruction' => ['<?php echo 1; ?><p>x</p>', 'malformed'],
            'doctype' => ['<!DOCTYPE html><p>x</p>', 'malformed'],
            'unclosed' => ['<div><span>a</div>', 'malformed'],
            'stray end tag' => ['<p>x</p></div>', 'malformed'],
            'mismatched' => ['<p><strong>x</p></strong>', 'malformed'],
            'bare lt' => ['<p>a < b</p>', 'malformed'],
            'lt in attribute' => ['<input type="text" name="q" placeholder="</textarea><img src=x onerror=alert(1)>">', 'attribute'],
            'nested form' => ['<form method="post" action="/contact/submit"><form method="post" action="/contact/submit"></form></form>', 'structure'],
            'slot marker text' => ['<p>TNCMSSLOT0123456789abcdef0123456789abcdef</p>', 'marker'],
            'slot marker class' => ['<div class="tncms-slot">x</div>', 'marker'],
            'tabindex positive' => ['<div tabindex="5">x</div>', 'value'],
            'empty output' => ['   ', 'empty'],
            'non utf8' => ["<p>\xC3\x28</p>", 'encoding'],
            'nul byte' => ["<p>a\0b</p>", 'encoding'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_dangerous_markup_rejects_the_whole_component(string $html, string $reason): void
    {
        $this->reject($html, $reason);
    }

    public function test_size_element_and_depth_limits(): void
    {
        $this->reject('<p>'.str_repeat('a', InteractiveHtmlProfile::MAX_BYTES).'</p>', 'limit');
        $this->reject(str_repeat('<span>x</span>', InteractiveHtmlProfile::MAX_ELEMENTS + 1), 'limit');
        $deep = str_repeat('<div>', InteractiveHtmlProfile::MAX_DEPTH + 1).'x'.str_repeat('</div>', InteractiveHtmlProfile::MAX_DEPTH + 1);
        $this->reject($deep, 'limit');

        // Exactly at the limit is fine.
        $ok = str_repeat('<div>', InteractiveHtmlProfile::MAX_DEPTH).'x'.str_repeat('</div>', InteractiveHtmlProfile::MAX_DEPTH);
        $this->assertStringContainsString('x', $this->profile()->validate($ok));
    }

    public function test_read_only_profile_requires_inert_controls_and_no_action(): void
    {
        $ro = $this->profile(readOnly: true);

        $this->assertStringContainsString('<fieldset disabled>', $ro->validate('<form method="post"><fieldset disabled><input type="text" name="a"><button type="submit">Go</button></fieldset></form>'));
        $this->assertStringContainsString('disabled', $ro->validate('<form><input type="text" name="a" disabled><button type="submit" disabled>Go</button></form>'));

        $this->reject('<form method="post" action="/contact/submit"><fieldset disabled><button type="submit">Go</button></fieldset></form>', 'readonly', $ro);
        $this->reject('<form method="post"><input type="text" name="a"></form>', 'readonly', $ro);
        $this->reject('<button type="submit">Go</button>', 'readonly', $ro);
        $this->reject('<select name="s"><option value="1">1</option></select>', 'readonly', $ro);
        $this->reject('<textarea name="m"></textarea>', 'readonly', $ro);
    }

    public function test_ordinary_sanitizer_is_not_the_profile(): void
    {
        // The global sanitizer still strips forms; the profile is never applied to user HTML.
        $this->assertSame('', cms_html('<form method="post" action="/contact/submit"><input type="text" name="a"></form>'));
        $this->assertSame('<p>x</p>', cms_html('<p onclick="alert(1)">x</p><script>alert(1)</script>'));
    }
}
