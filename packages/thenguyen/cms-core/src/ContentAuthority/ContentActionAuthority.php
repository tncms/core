<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use TheNguyen\CMS\Services\PermissionManager;

/**
 * Public, side-effect-free authorisation of content actions
 * (`cms.content.actions`, CORE-CONTENT-ACTION-AUTHORIZATION-R1).
 *
 * Answers whether an actor may perform a {@see ContentAction} on a Core post or
 * page with the same rules the Admin write authority ({@see ContentWriteGate})
 * applies to the equivalent ContentManager write — never more permissive. The
 * subject is re-read from the database (only SELECTs, no lock, no transaction,
 * no model events beyond `retrieved`, no hook, cache or log write), so a caller's
 * unsaved model never influences the answer.
 *
 * A decision is a precondition, not a reservation: mutations must still go
 * through ContentManager (or a ContentManager write unit), which re-checks
 * everything on the locked row.
 *
 * Extension-managed content (`cms_contents.managed_by` set) is refused for every
 * action except view: the write gate lets the owner's guard veto such writes and
 * Core cannot evaluate that guard without running extension code, so the only
 * answer that is never more permissive than the gate is a refusal — unless the
 * owner itself asks ({@see ContentActionContext::asOwningExtension()},
 * CORE-CONTENT-AUTHORIZATION-R2): then the guard is the asker's own business
 * approval, and Core answers its RBAC/ownership/state rules for the owner. The
 * owner is verified here, never taken from the caller: the claiming plugin file
 * must still resolve to an active, loadable plugin whose slug equals the
 * persisted `managed_by`. The guard is not consulted (no extension code runs).
 *
 * A private-draft context ({@see ContentActionContext::privateDraft()}) is valid
 * for edit / edit_translation only (anything else: unsupported_action) and lifts
 * exactly the "published content needs publish" rule. It is not write authority:
 * a Core write of published content still needs publish at ContentWriteGate.
 *
 * Check order (first refusal wins): action → actor → subject → base permission →
 * locale → ownership (author, then extension) → publish permission → state →
 * translation. A subject that is not a post or page is only reported as such to
 * an actor who may view posts or pages; anyone else gets permission_denied, so
 * the API is no content-existence oracle.
 *
 * Revocation applies per request: grants come from the request-scoped
 * PermissionManager memo, exactly as for the Admin write authority.
 */
final class ContentActionAuthority
{
    private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/';

    public function __construct(private readonly PermissionManager $permissions) {}

    public function decision(?Authenticatable $actor, ContentAction|string $action, mixed $subject, ?ContentActionContext $context = null): ContentActionDecision
    {
        $action = $action instanceof ContentAction ? $action : ContentAction::tryFrom($action);

        if ($action === null) {
            return ContentActionDecision::deny(null, ContentActionReason::UnsupportedAction);
        }

        $context ??= ContentActionContext::none();

        if ($context->privateDraft && ! in_array($action, [ContentAction::Edit, ContentAction::EditTranslation], true)) {
            return ContentActionDecision::deny($action, ContentActionReason::UnsupportedAction);
        }

        if ($actor === null) {
            return ContentActionDecision::deny($action, ContentActionReason::Unauthenticated);
        }

        $rbac = new ContentRbac($this->permissions);
        $row = $this->subjectRow($subject);

        if ($row === null) {
            $mayViewContent = $rbac->grants($actor, 'posts', 'view') || $rbac->grants($actor, 'pages', 'view');

            return ContentActionDecision::deny($action, $mayViewContent ? ContentActionReason::UnsupportedSubject : ContentActionReason::PermissionDenied);
        }

        $prefix = (string) ContentRbac::prefixFor((string) $row->type);

        if (! $rbac->grants($actor, $prefix, $action === ContentAction::View ? 'view' : 'edit')) {
            return ContentActionDecision::deny($action, ContentActionReason::PermissionDenied);
        }

        $locale = $context->locale;
        $owner = null;
        $translationAction = in_array($action, [ContentAction::CreateTranslation, ContentAction::EditTranslation], true);

        if (($locale === null && $translationAction) || ($locale !== null && ! $this->isActiveLocale($locale))) {
            return ContentActionDecision::deny($action, ContentActionReason::LocaleDenied);
        }

        if ($action !== ContentAction::View) {
            if (! ContentRbac::isAuthor($row->author_id, $actor) && ! $rbac->grants($actor, $prefix, 'edit_others')) {
                return ContentActionDecision::deny($action, ContentActionReason::OwnershipDenied);
            }

            if (is_string($row->managed_by) && $row->managed_by !== '') {
                $owner = $this->verifiedOwner($context, $row->managed_by);

                if ($owner === null) {
                    return ContentActionDecision::deny($action, ContentActionReason::OwnershipDenied);
                }
            }

            if (in_array($action, [ContentAction::Publish, ContentAction::Unpublish], true) && ! $rbac->grants($actor, $prefix, 'publish')) {
                return ContentActionDecision::deny($action, ContentActionReason::PermissionDenied);
            }
        }

        if ($row->deleted_at !== null) {
            return ContentActionDecision::deny($action, ContentActionReason::StatusDenied);
        }

        // Any edit of published content changes public output (ContentWriteGate::affectsPublicOutput).
        // A private draft leaves the public row untouched until a separate publish.
        if ($action !== ContentAction::View && ! $context->privateDraft && $row->status === 'published' && ! $rbac->grants($actor, $prefix, 'publish')) {
            return ContentActionDecision::deny($action, ContentActionReason::StatusDenied);
        }

        if ($translationAction) {
            $exists = DB::table('cms_content_translations')
                ->where('content_id', (int) $row->id)
                ->where('locale', $locale)
                ->exists();

            if ($exists !== ($action === ContentAction::EditTranslation)) {
                return ContentActionDecision::deny($action, ContentActionReason::LocaleDenied);
            }
        }

        return ContentActionDecision::allow($action, $context->privateDraft, $owner);
    }

    public function allows(?Authenticatable $actor, ContentAction|string $action, mixed $subject, ?ContentActionContext $context = null): bool
    {
        return $this->decision($actor, $action, $subject, $context)->allowed();
    }

    /**
     * The allowed decision, or a {@see ContentActionDenied} (HTTP 403).
     *
     * @throws ContentActionDenied
     */
    public function authorize(?Authenticatable $actor, ContentAction|string $action, mixed $subject, ?ContentActionContext $context = null): ContentActionDecision
    {
        $decision = $this->decision($actor, $action, $subject, $context);

        if ($decision->denied()) {
            throw new ContentActionDenied($decision);
        }

        return $decision;
    }

    /**
     * The persisted row of a supported subject (a saved post / page model or a
     * positive integer id, trashed rows included), or null.
     */
    private function subjectRow(mixed $subject): ?object
    {
        if ($subject instanceof Content) {
            $key = $subject->exists ? $subject->getKey() : null;
            $id = is_int($key) || (is_string($key) && ctype_digit($key)) ? (int) $key : null;
        } else {
            $id = $subject;
        }

        if (! is_int($id) || $id <= 0) {
            return null;
        }

        $row = DB::table('cms_contents')
            ->where('id', $id)
            ->first(['id', 'type', 'author_id', 'status', 'managed_by', 'deleted_at']);

        return $row !== null && ContentRbac::prefixFor((string) $row->type) !== null ? $row : null;
    }

    /**
     * The owner slug when the context's owner claim verifies against the persisted
     * `managed_by`: the claiming file resolves (now, not when the claim was made)
     * to that active plugin, and the plugin's runtime is loaded. Otherwise null.
     */
    private function verifiedOwner(ContentActionContext $context, string $managedBy): ?string
    {
        $file = $context->ownerClaimFile();

        if ($file === null) {
            return null;
        }

        $owner = app(ExtensionIdentityResolver::class)->ownerOfFile($file);

        if ($owner === null || $owner !== $managedBy || app('cms.extension')->isPluginUnloadable($owner)) {
            return null;
        }

        return $owner;
    }

    private function isActiveLocale(string $locale): bool
    {
        return preg_match(self::LOCALE_PATTERN, $locale) === 1 && app('cms.language')->isActive($locale);
    }
}
