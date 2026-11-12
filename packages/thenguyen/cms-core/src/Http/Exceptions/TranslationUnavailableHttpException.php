<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A resource exists but has no translation in the requested enabled locale (owner policy A,
 * CORE-RELEASE-BLOCKERS-R1-CLOSURE). It is an ordinary 404 for every consumer, so the theme-first
 * error contract (FrontendErrorResponder) renders it; the responder only reads the extra data to
 * show a localized "not available in this language" message and a link to a translation that
 * exists. It never carries content of the other language.
 */
final class TranslationUnavailableHttpException extends NotFoundHttpException
{
    /**
     * @param  string|null  $availableUrl  URL of the same resource in a locale that has it, or null
     */
    public function __construct(
        public readonly string $locale,
        public readonly ?string $availableUrl,
    ) {
        parent::__construct('Translation unavailable.', null, 0, ['X-Robots-Tag' => 'noindex']);
    }
}
