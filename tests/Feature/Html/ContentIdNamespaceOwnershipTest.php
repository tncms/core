<?php

declare(strict_types=1);

namespace Tests\Feature\Html;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * CORE-HTML-SANITIZER-A11Y-R1 (revision 2): `cms-content-*` is reserved for sanitized authored content.
 * Core-owned runtime code (PHP, Blade, JS, CSS of Core and the bundled default themes) must never use it for
 * controls, hooks or selectors. Documentation, the sanitizer's own validator and tests are classified separately.
 */
final class ContentIdNamespaceOwnershipTest extends TestCase
{
    /** Files that legitimately name the namespace: the contract's own validator, policy and sanitizer. */
    private const DEFINITION_FILES = [
        'packages/thenguyen/cms-core/src/Support/Html/SafeHtmlId.php',
        'packages/thenguyen/cms-core/src/Support/Html/AccessibleAttributePolicy.php',
        'packages/thenguyen/cms-core/src/Services/HtmlSanitizer.php',
    ];

    /** Core-owned runtime roots that render or script the page. */
    private const RUNTIME_ROOTS = [
        'packages/thenguyen/cms-core/src',
        'packages/thenguyen/cms-core/resources',
        'packages/thenguyen/cms-core/config',
        'app',
        'resources',
        'routes',
        'themes/default',
        'themes/default_v2',
        'plugins/hello-world',
    ];

    private const RUNTIME_EXTENSIONS = ['php', 'js', 'ts', 'vue', 'css', 'scss', 'json'];

    public function test_core_runtime_code_never_uses_the_authored_content_namespace(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach (self::RUNTIME_ROOTS as $root) {
            $path = base_path($root);
            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (! in_array(strtolower($file->getExtension()), self::RUNTIME_EXTENSIONS, true)
                    || str_contains($relative, '/node_modules/')
                    || in_array($relative, self::DEFINITION_FILES, true)) {
                    continue;
                }

                $scanned++;
                if (str_contains((string) file_get_contents($file->getPathname()), 'cms-content-')) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertGreaterThan(100, $scanned, 'the scan must actually cover Core runtime files');
        $this->assertSame([], $offenders, 'cms-content-* is reserved for sanitized authored content');
    }
}
