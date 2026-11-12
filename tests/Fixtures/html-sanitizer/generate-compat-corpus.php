<?php

declare(strict_types=1);

/*
 * Regenerates compat-corpus.json from the sanitizer in THIS checkout.
 *
 * Provenance: the committed JSON was produced by running this script on the unchanged 1.0.0-beta.7.1.39
 * HtmlSanitizer (base a972983e, sanitizer byte-identical to 78dc1763) BEFORE any R1 production change.
 * Re-running it after R1 must produce an identical file; HtmlSanitizerCompatibilityCorpusTest enforces it.
 *
 *   php tests/Fixtures/html-sanitizer/generate-compat-corpus.php
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';

$sanitizer = new TheNguyen\CMS\Services\HtmlSanitizer;
$cases = require __DIR__.'/corpus-inputs.php';
$out = [];
foreach ($cases as $name => $input) {
    $out[$name] = ['input' => $input, 'output' => $sanitizer->sanitize($input)];
}

file_put_contents(__DIR__.'/compat-corpus.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
echo count($out)." cases written\n";
