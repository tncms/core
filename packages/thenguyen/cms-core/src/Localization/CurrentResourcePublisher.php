<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-L10N.1B (Phase P3.3B) — the RENDER-BOUNDARY writer of the current
 * frontend resource.
 *
 * The render boundary (the controller / render pipeline that selects the view)
 * is the single place that KNOWS which resource a request renders, so it — not
 * SeoManager — owns publication to the ONE authority
 * ({@see CurrentResourceContext}). This service centralises Core's Content/Term
 * → {@see CurrentResourceReference} mapping so {@see \TheNguyen\CMS\Http\Controllers\FrontendController}
 * stays declarative and the type derivation lives in exactly one place.
 *
 * It is a thin, request-scoped writer: it builds no URL, resolves no
 * translation, and reads no request/global state. A PLUGIN publishes its own
 * resource the same way Core does here — by resolving {@see CurrentResourceContext}
 * and calling {@see CurrentResourceContext::publish()} with a reference it
 * builds — so no plugin needs this Core helper. Publication is write-once
 * (see the context): the first publish per request wins.
 */
final class CurrentResourcePublisher
{
    public function __construct(private readonly CurrentResourceContext $context) {}

    /** Publish the site root as the current resource. */
    public function publishHome(): void
    {
        $this->context->publish(CurrentResourceReference::home());
    }

    /** Publish a Core page/post as the current resource. */
    public function publishContent(Content $content): void
    {
        $type = $content->type === 'post' ? 'post' : 'page';

        $this->context->publish(
            CurrentResourceReference::of($type, $content, $content->getKey(), 'core'),
        );
    }

    /** Publish a Core category/tag archive as the current resource. */
    public function publishTerm(Term $term, string $type): void
    {
        $type = $type === 'tag' ? 'tag' : 'category';

        $this->context->publish(
            CurrentResourceReference::of($type, $term, $term->getKey(), 'core'),
        );
    }
}
