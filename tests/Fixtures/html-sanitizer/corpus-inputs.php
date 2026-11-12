<?php

declare(strict_types=1);

/*
 * CORE-HTML-SANITIZER-A11Y-R1 backward-compatibility corpus.
 *
 * Every input here must sanitize byte-identically before and after R1. Inputs either carry none of the
 * newly approved attributes (id, aria-label, aria-labelledby, aria-current) or carry them with values
 * or on elements that R1 still rejects. Expected outputs live in compat-corpus.json and were generated
 * from the unchanged 1.0.0-beta.7.1.39 sanitizer (see generate-compat-corpus.php in this directory).
 */
return [
    // ---- ordinary rich-editor markup -------------------------------------------------------------
    'paragraphs' => '<p>One</p><p>Two <strong>bold</strong> <em>em</em> <u>u</u> <s>s</s></p>',
    'headings' => '<h1>H1</h1><h2>H2</h2><h3>H3</h3><h4>H4</h4><h5>H5</h5><h6>H6</h6>',
    'lists' => '<ul><li>a</li><li>b<ol><li>c</li></ol></li></ul>',
    'classes' => '<p class="lead text-center">x</p><div class="note  warn">y</div>',
    'blockquote/pre/code' => '<blockquote><p>q</p></blockquote><pre><code>$a = 1;</code></pre>',
    'table' => '<table><thead><tr><th colspan="2">h</th></tr></thead><tbody><tr><td rowspan="2">a</td><td>b</td></tr></tbody></table>',
    'figure img' => '<figure><img src="/media/a.jpg" alt="A" title="T" width="10" height="20" loading="lazy"><figcaption>c</figcaption></figure>',
    'sectioning' => '<section><header>h</header><article><h2>t</h2></article><aside>s</aside><footer>f</footer></section><main>m</main><nav>n</nav>',
    'details' => '<details><summary>More</summary><p>Hidden</p></details>',
    'hr/br/span' => '<p>a<br>b</p><hr><span class="x">s</span>',
    'unicode vi' => '<p>Tiếng Việt có dấu — “quotes” &amp; ampersand</p>',
    'entities' => '<p>&lt;not a tag&gt; &copy; &nbsp; &#x1F600;</p>',
    'plain wrapper whitespace' => "  \n<p>trim me</p>\n  ",
    'unknown wrapper unwrapped' => '<font color="red">keep text</font><center>c</center>',
    'template unwrapped' => '<template><p class="t">x</p></template>',
    'trusted slot wrapper' => '<div class="tncms-slot">TNCMSSLOT0123456789abcdef0123456789abcdef</div>',
    'trusted slot fallback' => '<div class="tncms-slot tncms-slot--unavailable"></div>',

    // ---- links ---------------------------------------------------------------------------------------
    'link https' => '<a href="https://example.com/a?b=1&amp;c=2" title="t">x</a>',
    'link relative' => '<a href="/path/to">x</a><a href="page.html">y</a><a href="?q=1">z</a>',
    'link fragment kept (no target)' => '<a href="#results-region">jump</a>',
    'link fragment odd values' => '<a href="#">a</a><a href="#Top_1">b</a><a href="#%20x">c</a><a href="?p=2#pbab12cd">d</a>',
    'link mailto tel' => '<a href="mailto:a@b.test">m</a><a href="tel:+1">t</a>',
    'link blank gets rel' => '<a href="https://e.test" target="_blank">x</a>',
    'link blank replaces rel' => '<a href="https://e.test" target="_blank" rel="author">x</a>',
    'link rel kept' => '<a href="/a" rel="nofollow">x</a>',
    'link protocol-relative (pre-existing F-1)' => '<a href="//evil.example/x">x</a>',

    // ---- unsafe URLs (must stay removed) -------------------------------------------------------------
    'js href' => '<a href="javascript:alert(1)">x</a>',
    'js href mixed case' => '<a href="JaVaScRiPt:alert(1)">x</a>',
    'js href whitespace' => '<a href=" javascript:alert(1)">x</a>',
    'js href newline' => "<a href=\"java\nscript:alert(1)\">x</a>",
    'js href tab' => "<a href=\"java\tscript:alert(1)\">x</a>",
    'js href entity' => '<a href="&#106;avascript:alert(1)">x</a>',
    'js href hex entity' => '<a href="&#x6A;avascript:alert(1)">x</a>',
    'vbscript href' => '<a href="vbscript:msgbox(1)">x</a>',
    'data href' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    'file href' => '<a href="file:///etc/passwd">x</a>',
    'img js src' => '<img src="javascript:alert(1)">',
    'img no src' => '<img alt="x">',
    'img data svg' => '<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">',
    'img data png kept' => '<img src="data:image/png;base64,iVBORw0KGgo=">',

    // ---- dangerous tags and attributes ---------------------------------------------------------------
    'script' => '<p>a</p><script>alert(1)</script><p>b</p>',
    'style tag' => '<style>body{}</style><p>x</p>',
    'iframe' => '<iframe src="https://e.test"></iframe><p>x</p>',
    'object/embed' => '<object data="x"></object><embed src="y"><p>z</p>',
    'form controls' => '<form action="/x"><input name="a"><button>b</button><select><option>o</option></select><textarea>t</textarea></form><p>k</p>',
    'svg payload' => '<svg><script>alert(1)</script><a xlink:href="javascript:alert(1)">x</a></svg><p>k</p>',
    'svg onload' => '<svg onload="alert(1)"></svg><p>k</p>',
    'math payload' => '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
    'noscript' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></p></noscript>',
    'meta/base/link' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)"><base href="https://evil.test/"><link rel="stylesheet" href="x"><p>k</p>',
    'event handlers' => '<p onclick="alert(1)" onmouseover="x()">a</p><img src="/a.png" onerror="alert(1)">',
    'inline style' => '<p style="background:url(javascript:alert(1))">a</p>',
    'data attributes' => '<p data-x="1" data-status="ok" data-cms-root="1">a</p>',
    'role attribute' => '<nav role="navigation"><p role="status">a</p></nav>',
    'name attribute' => '<a name="top">t</a><img src="/a.png" name="cookie">',
    'tabindex/hidden/lang' => '<p tabindex="0" hidden lang="vi" dir="rtl">a</p>',
    'comment' => '<p>a<!-- secret --></p><!--[if IE]><script>alert(1)</script><![endif]-->',
    'cdata/pi' => '<p>a<![CDATA[x]]></p><?php echo 1; ?>',
    'breakout attempt' => '<p title="x" onmouseover=alert(1) x="">a</p>',
    'unclosed tags' => '<p><strong>unclosed <em>nested',
    'misnested' => '<p><div>x</p></div>',

    // ---- ids and ARIA that R1 still rejects (must stay identical) ------------------------------------
    'id no hyphen' => '<section id="results">a</section>',
    'id ns-style no hyphen' => '<section id="pbab12cd">a</section>',
    'id uppercase' => '<section id="Results-Region">a</section>',
    'id leading digit' => '<p id="1-a">a</p>',
    'id leading hyphen' => '<p id="-a-b">a</p>',
    'id trailing hyphen' => '<p id="a-b-">a</p>',
    'id double hyphen' => '<p id="a--b">a</p>',
    'id underscore' => '<p id="a_b">a</p>',
    'id colon/dot' => '<p id="a:b">a</p><p id="a.b">b</p>',
    'id whitespace' => '<p id=" a-b ">a</p><p id="a b">b</p>',
    'id empty' => '<p id="">a</p><p id>b</p>',
    'id too long' => '<p id="a-'.str_repeat('b', 63).'">a</p>',
    'id reserved prefix' => '<div id="tncms-slot">a</div>',
    'id clobber names' => '<img src="/a.png" id="cookie"><p id="location">l</p><p id="__proto__">p</p>',
    'id non-ascii' => '<p id="kết-quả">a</p><p id="а-b">cyrillic a</p>',
    'id quote breakout' => '<p id="a-b&quot;onmouseover=&quot;alert(1)">a</p>',
    'aria other names' => '<nav aria-hidden="true" aria-live="polite" aria-describedby="x-y" aria-expanded="true" aria-controls="x-y"><p>a</p></nav>',
    'aria wildcard style' => '<p aria-foo="x" aria-="y" aria-label-x="z">a</p>',
    'aria-label on generic' => '<p aria-label="x">a</p><div aria-label="y">b</div><span aria-label="z">c</span>',
    'aria-label empty/space' => '<nav aria-label="">a</nav><nav aria-label="   ">b</nav><nav aria-label>c</nav>',
    'aria-label control chars' => "<nav aria-label=\"a\u{0007}b\">a</nav><nav aria-label=\"a\u{202E}b\">b</nav><nav aria-label=\"a\u{200B}b\">c</nav><nav aria-label=\"a\u{2028}b\">d</nav>",
    'aria-label too long' => '<nav aria-label="'.str_repeat('x', 161).'">a</nav>',
    'aria-current bad values' => '<a href="/a" aria-current="Page">a</a><a href="/b" aria-current="yes">b</a><a href="/c" aria-current="">c</a><a href="/d" aria-current>d</a>',
    'aria-current wrong element' => '<p aria-current="page">a</p><span aria-current="true">b</span>',
    'aria-labelledby dangling' => '<section aria-labelledby="missing-id"><h2>t</h2></section>',
    'aria-labelledby bad grammar' => '<section aria-labelledby="Bad_Ref"><h2>t</h2></section><nav aria-labelledby="a-b  c-d">n</nav>',
    'aria-labelledby on generic' => '<div aria-labelledby="x-y">a</div>',
    'aria-labelledby too many' => '<nav aria-labelledby="a-a b-b c-c d-d e-e">n</nav>',
];
