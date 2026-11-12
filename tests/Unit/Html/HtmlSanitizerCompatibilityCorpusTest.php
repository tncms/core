<?php

declare(strict_types=1);

namespace Tests\Unit\Html;

use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Services\HtmlSanitizer;

/**
 * CORE-HTML-SANITIZER-A11Y-R1 backward compatibility: compat-corpus.json was generated from the unchanged
 * 1.0.0-beta.7.1.39 sanitizer. Markup without the newly approved attributes, or with values R1 still rejects,
 * must keep producing exactly that output.
 */
final class HtmlSanitizerCompatibilityCorpusTest extends TestCase
{
    private const CORPUS_SHA256 = '78cf2b089a5e1b1e50417934f6cc14e92514da12bc77c873b887414f4ae96ecb';

    /** @return array<string, array{input: string, output: string}> */
    private function corpus(): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/html-sanitizer/compat-corpus.json';
        $raw = (string) file_get_contents($path);

        $this->assertSame(self::CORPUS_SHA256, hash('sha256', $raw), 'the baseline corpus must not be regenerated');

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_corpus_covers_every_input_case(): void
    {
        $inputs = require dirname(__DIR__, 2).'/Fixtures/html-sanitizer/corpus-inputs.php';

        $this->assertSame(array_keys($inputs), array_keys($this->corpus()));
        $this->assertCount(89, $inputs);
    }

    public function test_output_is_identical_to_the_baseline(): void
    {
        $sanitizer = new HtmlSanitizer;

        foreach ($this->corpus() as $name => $case) {
            $this->assertSame($case['output'], $sanitizer->sanitize($case['input']), $name);
        }
    }

    public function test_baseline_output_is_idempotent(): void
    {
        $sanitizer = new HtmlSanitizer;

        foreach ($this->corpus() as $name => $case) {
            $this->assertSame($case['output'], $sanitizer->sanitize($case['output']), $name);
        }
    }

    public function test_null_and_empty_are_unchanged(): void
    {
        $sanitizer = new HtmlSanitizer;

        $this->assertSame('', $sanitizer->sanitize(null));
        $this->assertSame('', $sanitizer->sanitize(''));
        $this->assertSame('', $sanitizer->sanitize("  \n "));
    }
}
