<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Content\Extension\FrontPage;
use TheNguyen\CMS\Services\PermissionManager;
use Throwable;

/**
 * The single authorisation point of every sanctioned content write
 * (`cms.content.gate`, CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * ContentManager builds a {@see ContentWriteIntent} from the LOCKED row inside
 * the write transaction and calls {@see authorize()} before writing anything.
 * Order: attribution → revision → owner guard (managed content) → RBAC
 * (base permission, ownership, publication). Any refusal throws, so the
 * transaction rolls back.
 *
 * It also tracks the "sanctioned write" scope that the Content model events use
 * to refuse direct Eloquent writes to managed content.
 */
final class ContentWriteGate
{
    /** Row fields whose change on a published record changes public output. */
    public const EDITORIAL_FIELDS = [
        'type', 'author_id', 'parent_id', 'template', 'featured_image', 'sort_order', 'comment_status',
        'views_count', 'comments_count', 'is_featured', 'show_page_title', 'published_at',
    ];

    /** Content types whose writes are governed by `{type}s.*` permissions. */
    private const RBAC_PREFIXES = ['post' => 'posts', 'page' => 'pages'];

    /** @var array<int, int> content id => sanction depth */
    private array $sanctioned = [];

    private int $sanctionedNew = 0;

    public function __construct(
        private readonly PermissionManager $permissions,
        private readonly ContentWriteGuardRegistry $guards,
    ) {}

    public function authorize(ContentWriteIntent $intent): void
    {
        $context = $intent->context;
        $actor = $intent->actor;
        $managedOwner = $intent->owner;

        if ($context->actorMismatch) {
            $this->deny($intent, 'actor_not_signed_in', 'An extension can only act as the signed-in user.');
        }

        if (! $context->isSystem() && $actor === null) {
            $this->deny($intent, 'unattributed_write', 'Content can only be changed by a signed-in user or a sanctioned system operation.');
        }

        $this->checkOwnershipChange($intent);

        if ($managedOwner !== null && $context->origin === ContentWriteContext::LEGACY) {
            $this->deny($intent, 'managed_requires_context', 'This content is managed by an extension and cannot be changed through this path.');
        }

        $this->checkRevision($intent, $managedOwner !== null);

        // Detach is the recovery path for a missing owner: its guard is consulted
        // only while that owner is still active.
        if ($managedOwner !== null
            && ! (in_array($intent->operation, [ContentOperation::Detach, ContentOperation::Transfer], true) && $this->guards->guardFor($managedOwner) === null)) {
            $this->consultGuard($intent, $managedOwner);
        }

        if (! $context->isSystem()) {
            $this->checkPermissions($intent, $actor);
        }
    }

    // ---------------------------------------------------------------------
    // Sanctioned-write scope (consumed by Content / ContentTranslation events)
    // ---------------------------------------------------------------------

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function sanction(int $contentId, Closure $callback): mixed
    {
        $this->sanctioned[$contentId] = ($this->sanctioned[$contentId] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            if (--$this->sanctioned[$contentId] <= 0) {
                unset($this->sanctioned[$contentId]);
            }
        }
    }

    /**
     * Sanction the insert of a NEW row (it has no id yet).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function sanctionNew(Closure $callback): mixed
    {
        $this->sanctionedNew++;

        try {
            return $callback();
        } finally {
            $this->sanctionedNew--;
        }
    }

    public function isSanctioned(?int $contentId): bool
    {
        if ($contentId === null) {
            return $this->sanctionedNew > 0;
        }

        return isset($this->sanctioned[$contentId]);
    }

    // ---------------------------------------------------------------------
    // Steps
    // ---------------------------------------------------------------------

    private function checkRevision(ContentWriteIntent $intent, bool $managed): void
    {
        if ($intent->operation === ContentOperation::Create) {
            return;
        }

        if ($intent->expectedRevision === null) {
            if ($intent->context->requiresRevision() || ($managed && ! $intent->context->isSystem())) {
                $this->deny($intent, 'revision_required', 'This change is missing the content revision it was based on. Reload the content and try again.');
            }

            return;
        }

        if ($intent->expectedRevision !== $intent->currentRevision) {
            throw new ContentRevisionConflict((int) $intent->contentId, $intent->expectedRevision, $intent->currentRevision);
        }
    }

    private function consultGuard(ContentWriteIntent $intent, string $owner): void
    {
        $guard = $this->guards->guardFor($owner);

        if ($guard === null) {
            $this->deny($intent, 'owner_inactive', 'This content is managed by an extension that is not active. It cannot be changed until the extension is active again or an administrator detaches it.');
        }

        try {
            $decision = $guard->check($intent);
        } catch (ContentWriteException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Content write guard failed; write refused.', [
                'owner' => $owner,
                'content_id' => $intent->contentId,
                'operation' => $intent->operation->value,
                'exception' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);

            $this->deny($intent, 'guard_failed', 'The extension that manages this content could not approve the change, so nothing was saved.');
        }

        if (! $decision instanceof ContentWriteDecision
            || (! $decision->allowed && preg_match('/^[a-z0-9_.-]{1,64}$/', $decision->code) !== 1)) {
            Log::warning('Content write guard returned a malformed decision; write refused.', [
                'owner' => $owner,
                'content_id' => $intent->contentId,
                'operation' => $intent->operation->value,
            ]);

            $this->deny($intent, 'guard_invalid_decision', 'The extension that manages this content returned an invalid answer, so nothing was saved.');
        }

        if (! $decision->allowed) {
            $message = trim($decision->message) !== '' ? mb_substr(trim($decision->message), 0, 500) : 'The extension that manages this content does not allow this change.';

            throw new ContentWriteDenied('guard_denied.'.$decision->code, $message, $intent->contentId, $owner);
        }
    }

    /**
     * Ownership changes (CORE-UNIFIED-OWNERSHIP-R1): creating owned content,
     * adopting existing content and operator transfer. Each needs a trusted
     * actor, a content type Core governs with permissions, and never the
     * front page. Adoption additionally needs the explicitly granted
     * `content.ownership.adopt`, which super-admin status does not imply.
     */
    private function checkOwnershipChange(ContentWriteIntent $intent): void
    {
        $context = $intent->context;
        $ownedCreate = $intent->operation === ContentOperation::Create && $context->claimsOwnership;
        $adopt = $intent->operation === ContentOperation::Claim;
        $transfer = $intent->operation === ContentOperation::Transfer;

        if (! $ownedCreate && ! $adopt && ! $transfer) {
            return;
        }

        if ($transfer) {
            if ($context->operator === null || ! $this->permissions->userCanStrict($context->operator, 'content.authority.recover')) {
                $this->deny($intent, 'unauthorized_transfer', 'Only an operator with the content recovery permission can transfer content ownership.');
            }
        } elseif ($context->origin !== ContentWriteContext::EXTENSION || ! $context->actorIsSessionUser) {
            $this->deny($intent, 'ownership_requires_signed_in_user', 'Content ownership can only change for the signed-in user.');
        }

        if (! isset(self::RBAC_PREFIXES[$intent->type])) {
            $this->deny($intent, 'unsupported_content_type', 'This type of content cannot be managed by an extension.');
        }

        // Releasing the front page back to Core is always allowed (recovery).
        $toPlugin = ! $transfer || ($intent->changes['row']['managed_by'] ?? null) !== null;

        if (($adopt || $transfer) && $toPlugin && $intent->contentId !== null && FrontPage::is($intent->contentId, $intent->type)) {
            $this->deny($intent, 'front_page', 'The front page cannot be managed by an extension.');
        }

        if ($adopt && ! $this->permissions->userHasExplicitGrant($intent->actor, 'content.ownership.adopt')) {
            $this->deny($intent, 'unauthorized_adopt', 'You are not allowed to let an extension take over existing content.');
        }
    }

    /**
     * Row-independent adoption checks run before the content row is locked
     * (CORE-UNIFIED-OWNERSHIP-R1 L3): signed-in actor and explicit grant.
     * The full check runs again inside the locked transaction.
     */
    public function preauthorizeAdoption(ContentWriteContext $context, int $contentId): void
    {
        if ($context->origin !== ContentWriteContext::EXTENSION) {
            return; // refused later with claim_requires_extension
        }

        if ($context->actorMismatch) {
            throw new ContentWriteDenied('actor_not_signed_in', 'An extension can only act as the signed-in user.', $contentId);
        }

        if ($context->actor === null) {
            throw new ContentWriteDenied('unattributed_write', 'Content can only be changed by a signed-in user or a sanctioned system operation.', $contentId);
        }

        if (! $context->actorIsSessionUser) {
            throw new ContentWriteDenied('ownership_requires_signed_in_user', 'Content ownership can only change for the signed-in user.', $contentId);
        }

        if (! $this->permissions->userHasExplicitGrant($context->actor, 'content.ownership.adopt')) {
            throw new ContentWriteDenied('unauthorized_adopt', 'You are not allowed to let an extension take over existing content.', $contentId);
        }
    }

    public function preauthorizeTransfer(ContentWriteContext $context, int $contentId): void
    {
        if ($context->operator === null || ! $this->permissions->userCanStrict($context->operator, 'content.authority.recover')) {
            throw new ContentWriteDenied('unauthorized_transfer', 'Only an operator with the content recovery permission can transfer content ownership.', $contentId);
        }
    }

    private function checkPermissions(ContentWriteIntent $intent, Authenticatable $actor): void
    {
        if ($intent->operation === ContentOperation::Transfer) {
            return; // authorised in checkOwnershipChange()
        }

        if ($intent->operation === ContentOperation::Detach) {
            if (! $this->permissions->userCanStrict($actor, 'content.authority.recover')) {
                $this->deny($intent, 'unauthorized_recovery', 'You are not allowed to detach extension-managed content.');
            }

            return;
        }

        $prefix = self::RBAC_PREFIXES[$intent->type] ?? null;

        if ($prefix === null) {
            return;
        }

        $isOwn = $this->isOwn($intent, $actor);

        switch ($intent->operation) {
            case ContentOperation::Create:
                $this->require($intent, $actor, $prefix.'.create', 'unauthorized_create', 'You are not allowed to create this content.');

                // Creating content in someone else's name is editing others' content.
                if (! $isOwn) {
                    $this->require($intent, $actor, $prefix.'.edit_others', 'unauthorized_edit_others', 'You are not allowed to create content in another user\'s name.', strict: true);
                }
                break;
            case ContentOperation::Delete:
                $this->require($intent, $actor, $prefix.'.delete', 'unauthorized_delete', 'You are not allowed to delete this content.');

                if (! $isOwn) {
                    $this->require($intent, $actor, $prefix.'.delete_others', 'unauthorized_delete_others', 'You are not allowed to delete content written by someone else.', strict: true);
                }
                break;
            default:
                $this->require($intent, $actor, $prefix.'.edit', 'unauthorized_edit', 'You are not allowed to edit this content.');

                if (! $isOwn) {
                    $this->require($intent, $actor, $prefix.'.edit_others', 'unauthorized_edit_others', 'You are not allowed to edit content written by someone else.', strict: true);
                }
        }

        if ($intent->changesField('author_id') && $intent->operation !== ContentOperation::Create) {
            $this->require($intent, $actor, $prefix.'.edit_others', 'unauthorized_author_change', 'You are not allowed to change the author of this content.', strict: true);
        }

        if ($this->affectsPublicOutput($intent)) {
            $this->require($intent, $actor, $prefix.'.publish', 'unauthorized_publish', 'You are not allowed to publish, unpublish or change published content.', strict: true);
        }
    }

    /** Ownership is judged on the locked row's author (create: the proposed author). */
    private function isOwn(ContentWriteIntent $intent, Authenticatable $actor): bool
    {
        $author = $intent->operation === ContentOperation::Create
            ? ($intent->changes['row']['author_id'] ?? null)
            : ($intent->current['row']['author_id'] ?? null);

        return $author !== null && (string) $author === (string) $actor->getAuthIdentifier();
    }

    /** Whether the write creates, removes or changes publicly visible output. */
    public function affectsPublicOutput(ContentWriteIntent $intent): bool
    {
        $wasPublic = ($intent->current['row']['status'] ?? null) === 'published';
        $statusChange = $intent->changes['row']['status'] ?? null;

        return match ($intent->operation) {
            ContentOperation::Create => $statusChange === 'published',
            ContentOperation::Delete => $wasPublic,
            ContentOperation::Claim, ContentOperation::Detach, ContentOperation::Transfer => false,
            default => ($statusChange !== null && ($wasPublic || $statusChange === 'published'))
                || ($wasPublic && $this->hasEditorialChange($intent)),
        };
    }

    private function hasEditorialChange(ContentWriteIntent $intent): bool
    {
        if ($intent->changesTranslations() || $intent->changesTerms()) {
            return true;
        }

        foreach (self::EDITORIAL_FIELDS as $field) {
            if ($intent->changesField($field)) {
                return true;
            }
        }

        return false;
    }

    private function require(ContentWriteIntent $intent, Authenticatable $actor, string $permission, string $code, string $message, bool $strict = false): void
    {
        $granted = $strict
            ? $this->permissions->userCanStrict($actor, $permission)
            : $this->permissions->userCan($actor, $permission);

        if (! $granted) {
            $this->deny($intent, $code, $message);
        }
    }

    private function deny(ContentWriteIntent $intent, string $code, string $message): never
    {
        throw new ContentWriteDenied($code, $message, $intent->contentId, $intent->owner);
    }
}
