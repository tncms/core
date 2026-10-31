<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * Owner-supplied SEO values (CORE-CONTENT-EXT-R1). Canonical, hreflang,
 * og:url, og:type, locale and keywords are deliberately absent: Core owns them.
 * Core sanitizes every field ({@see sanitized()}); an invalid field is dropped
 * on its own, an empty one means "keep Core's value".
 *
 * The image is the exception (CORE-38-TOPLIST-SEAMS-R1): a null ogImage keeps
 * Core's fallback (featured image, then site default), but suppressOgImage —
 * or a supplied image that is empty or invalid — means the owner approved no
 * image, so Core emits none rather than substituting an unrelated one.
 */
final class ContentSeoOverride
{
    public const TITLE_MAX = 200;

    public const DESCRIPTION_MAX = 500;

    private const URL_MAX = 2048;

    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly ?string $ogImage = null,
        public readonly ?RobotsRestriction $robots = null,
        public readonly bool $suppressOgImage = false,
    ) {}

    public function sanitized(): self
    {
        $image = self::image($this->ogImage);
        $suppress = $this->suppressOgImage || ($this->ogImage !== null && $image === null);

        return new self(
            title: self::text($this->title, self::TITLE_MAX),
            description: self::text($this->description, self::DESCRIPTION_MAX),
            ogTitle: self::text($this->ogTitle, self::TITLE_MAX),
            ogDescription: self::text($this->ogDescription, self::DESCRIPTION_MAX),
            ogImage: $suppress ? null : $image,
            robots: $this->robots,
            suppressOgImage: $suppress,
        );
    }

    /** The same override with noindex added (nofollow kept when present). */
    public function withNoIndex(): self
    {
        $robots = $this->robots?->nofollow() ? RobotsRestriction::NoIndexNoFollow : RobotsRestriction::NoIndex;

        return new self($this->title, $this->description, $this->ogTitle, $this->ogDescription, $this->ogImage, $robots, $this->suppressOgImage);
    }

    public function isEmpty(): bool
    {
        return $this->title === null && $this->description === null && $this->ogTitle === null
            && $this->ogDescription === null && $this->ogImage === null && $this->robots === null
            && ! $this->suppressOgImage;
    }

    /** Plain text: tags and control characters removed, whitespace collapsed, bounded. */
    private static function text(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = strip_tags(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) > $max) {
            return null;
        }

        return $text;
    }

    /** An absolute https URL with a host, or a same-origin root-relative path. */
    private static function image(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || strlen($url) > self::URL_MAX || preg_match('/[\s\x00-\x1F\x7F"\'<>\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
