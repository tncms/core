<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use TheNguyen\CMS\Services\ExtensionIdentityResolver;

/**
 * Owner of the nearest plugin frame on the call stack — the same rule the
 * trusted script/component registries use (CORE-EXTENSION-TRUST S1/S2).
 *
 * Returns the active plugin slug, '' for a frame inside an inactive or
 * untrusted plugin directory, or false when no plugin frame exists (Core,
 * theme, host, console or test code). Caller-supplied identifiers are never
 * consulted. This guards against honest mistakes and competing plugins, not a
 * malicious active plugin (which runs trusted PHP in-process).
 */
final class CallSitePlugin
{
    /** @var list<string> */
    private array $skip;

    /** @param  list<string>  $skipFiles  frames to ignore (the calling Core service itself) */
    public function __construct(private readonly ExtensionIdentityResolver $identity, array $skipFiles)
    {
        $this->skip = array_map($this->normalize(...), [
            ...$skipFiles,
            __FILE__,
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'helpers.php',
        ]);
    }

    public function resolve(): string|false
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || in_array($this->normalize($file), $this->skip, true)) {
                continue;
            }

            if ($this->identity->isUnderPluginsRoot($file)) {
                return $this->identity->ownerOfFile($file) ?? '';
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
