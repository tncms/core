<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\SeoManager;

/** The gate's verdict for content that will render with 200. */
final class PreparedContent
{
    public const MANAGED_CACHE_CONTROL = 'private, max-age=0, must-revalidate';

    public function __construct(
        public readonly ?ContentOwnershipMarker $ownership = null,
        public readonly ?ContentSeoOverride $seoOverride = null,
        public readonly bool $neutralSeo = false,
    ) {}

    /**
     * Apply the owner's override after SeoManager::forContent(), or the
     * neutral noindex head when the owner's SEO provider failed
     * (CORE-38-TOPLIST-SEAMS-R1).
     */
    public function applySeo(SeoManager $seo): void
    {
        if ($this->neutralSeo) {
            $seo->applyNeutralContentSeo();
        } elseif ($this->seoOverride !== null) {
            $seo->applyContentOverride($this->seoOverride);
        }
    }

    /** Managed pages are dynamically controlled: browsers must revalidate (D-4). */
    public function finalize(Response $response): Response
    {
        if ($this->ownership !== null && $response->getStatusCode() === 200) {
            $response->headers->set('Cache-Control', self::MANAGED_CACHE_CONTROL);
        }

        return $response;
    }
}
