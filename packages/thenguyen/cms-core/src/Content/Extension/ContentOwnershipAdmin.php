<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use TheNguyen\CMS\Services\ExtensionManager;

/**
 * Operator-controlled ownership changes for EXISTING content, bound as
 * `cms.content_ownership_admin` and driven by the `cms:content-owner`
 * command (CORE-CONTENT-EXT-R1 D-7; single authority since
 * CORE-UNIFIED-OWNERSHIP-R1).
 *
 * Refuses web requests and any call that originates in plugin code, so it is
 * never reachable through a plugin API. Each change names the operator (a
 * user holding the strict `content.authority.recover` permission), the owner
 * the operator expects and the target owner, and runs as
 * ContentManager::transferOwnership(): compare-and-set under the row lock,
 * revision checked, front page refused, current owner's guard consulted while
 * it is active, audited with the operator and reason.
 */
final class ContentOwnershipAdmin
{
    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    private CallSitePlugin $callSite;

    public function __construct(
        private readonly ExtensionManager $extensions,
        ExtensionIdentityResolver $identity,
    ) {
        $this->callSite = new CallSitePlugin($identity, [__FILE__]);
    }

    /**
     * Assign $owner to existing content. $expectedOwner is the owner the
     * operator saw (null = currently Core content).
     */
    public function assign(int $contentId, string $owner, ContentFallbackPolicy $fallback, ?string $expectedOwner, Authenticatable $operator, string $reason = 'operator assignment'): void
    {
        $this->requireOperator();

        if (preg_match(self::SLUG, $owner) !== 1 || $this->extensions->findPlugin($owner) === null) {
            throw new ContentOwnershipException('unknown_plugin');
        }

        $this->transfer($contentId, $expectedOwner, $owner, $fallback, $operator, $reason);
    }

    /** Return owned content to Core. */
    public function release(int $contentId, string $expectedOwner, Authenticatable $operator, string $reason = 'operator release'): void
    {
        $this->requireOperator();
        $this->transfer($contentId, $expectedOwner, null, null, $operator, $reason);
    }

    /** The configured static front page or the conventional home page (D-8). */
    public function isFrontPage(Content $content): bool
    {
        return FrontPage::is((int) $content->getKey(), (string) $content->type);
    }

    private function transfer(int $contentId, ?string $from, ?string $to, ?ContentFallbackPolicy $fallback, Authenticatable $operator, string $reason): void
    {
        $content = Content::query()->whereKey($contentId)->first(['id', 'type', 'revision']);

        if ($content === null) {
            throw new ContentOwnershipException('unknown_content');
        }

        try {
            app('cms.content')->transferOwnership(
                $contentId, (int) $content->getAttribute('revision'), $from, $to, $fallback,
                ContentWriteContext::operator($operator), $reason,
            );
        } catch (ContentWriteDenied $e) {
            throw new ContentOwnershipException(match ($e->errorCode()) {
                'ownership_changed', 'no_change' => 'ownership_changed',
                'front_page' => 'front_page',
                'unsupported_content_type' => 'unknown_content',
                default => $e->errorCode(),
            });
        }

        Log::info('TN CMS content ownership transferred', ['content_id' => $contentId, 'from' => $from, 'to' => $to]);
    }

    private function requireOperator(): void
    {
        // Operator = console (cms:content-owner), never plugin code or a web request.
        if (! app()->runningInConsole() || ContentWriteContext::inHttpRequest() || $this->callSite->resolve() !== false) {
            throw new ContentOwnershipException('operator_only');
        }

        if (! app('cms.content_ownership')->ready()) {
            throw new ContentOwnershipException('unsupported');
        }
    }
}
