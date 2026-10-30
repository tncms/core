<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Immutable description of one sanctioned content write, handed to the owner
 * guard (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * `current` and `changes` are built by Core from the LOCKED database row (never
 * from the caller's in-memory model) and from the normalised values Core is
 * about to persist, so a guard sees exactly what would change.
 *
 *  - current: ['row' => [field => value], 'translations' => [locale => [field => value]], 'term_ids' => int[]]
 *  - changes: ['row' => [field => new], 'translations' => [locale => [field => new]], 'term_ids' => int[]|absent]
 *    (only fields whose value actually differs; empty for delete/restore/detach)
 */
final class ContentWriteIntent
{
    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $locales  locales whose translation this write touches
     */
    public function __construct(
        public readonly ContentOperation $operation,
        public readonly ?int $contentId,
        public readonly string $type,
        public readonly ?string $owner,
        public readonly array $current,
        public readonly array $changes,
        public readonly array $locales,
        public readonly ?Authenticatable $actor,
        public readonly ?int $expectedRevision,
        public readonly int $currentRevision,
        public readonly ContentWriteContext $context,
    ) {}

    /** Whether this write comes from the given plugin's own code (server-derived). */
    public function isFromExtension(string $slug): bool
    {
        return $this->context->origin === ContentWriteContext::EXTENSION && $this->context->extension === $slug;
    }

    public function changesField(string $field): bool
    {
        return array_key_exists($field, $this->changes['row'] ?? []);
    }

    public function changesTranslations(): bool
    {
        return ($this->changes['translations'] ?? []) !== [];
    }

    public function changesTerms(): bool
    {
        return array_key_exists('term_ids', $this->changes);
    }
}
