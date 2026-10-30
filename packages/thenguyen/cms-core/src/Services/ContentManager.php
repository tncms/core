<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\ContentAuthority\ContentAuthorityAudit;
use TheNguyen\CMS\ContentAuthority\ContentOperation;
use TheNguyen\CMS\ContentAuthority\ContentRevisionConflict;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;
use TheNguyen\CMS\ContentAuthority\ContentWriteGate;
use TheNguyen\CMS\ContentAuthority\ContentWriteIntent;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Revision\DTOs\RevisionContext;

/**
 * The Core write authority for content rows, their translations, slugs and
 * term assignments.
 *
 * Every write (CORE-STRUCTURED-CONTENT-AUTHORITY-R1):
 *   1. runs in one transaction and locks the content row (then its translation
 *      rows, by locale) — lock order: cms_contents → cms_content_translations →
 *      extension rows → pb_layouts;
 *   2. builds a {@see ContentWriteIntent} from the LOCKED row and the normalised
 *      values it is about to persist (never from the caller's in-memory model);
 *   3. asks {@see ContentWriteGate} (attribution, expected revision, owner guard,
 *      RBAC: base permission, ownership, publication) — a refusal throws and
 *      rolls everything back;
 *   4. writes inside the gate's sanctioned scope and bumps `revision` once
 *      (once per {@see transaction()} unit when nested);
 *   5. fires `cms.content.saved` after its own transaction and
 *      `cms.content.committed` after the OUTERMOST commit.
 *
 * The optional `$expectedRevision` / `$context` parameters keep the pre-R1
 * signatures source-compatible; their legacy use is restricted (see
 * {@see ContentWriteContext}).
 */
class ContentManager
{
    /** @var array<int, array{start: int, dirty: bool}> open write units by content id */
    private array $units = [];

    public function __construct(
        private readonly SlugManager $slugManager,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?ContentWriteContext $context = null): Content
    {
        $context ??= ContentWriteContext::legacy();

        $content = DB::transaction(function () use ($data, $context): Content {
            $attributes = $this->contentAttributes($data, isNew: true, actorId: $this->actorId($context));
            $owner = $context->claimsOwnership ? $context->extension : null;

            if ($owner !== null && \TheNguyen\CMS\Content\Extension\FrontPage::isConventionalHome((string) ($attributes['type'] ?? ''), (string) ($data['slug'] ?? ''), (string) ($data['locale'] ?? ''))) {
                throw new ContentWriteDenied('front_page', 'The front page cannot be managed by an extension.', null, $owner);
            }

            $this->gate()->authorize(new ContentWriteIntent(
                operation: ContentOperation::Create,
                contentId: null,
                type: (string) $attributes['type'],
                owner: $owner,
                current: ['row' => [], 'translations' => [], 'term_ids' => []],
                changes: ['row' => $this->normalizeRow($attributes)],
                locales: [(string) ($data['locale'] ?? app('cms.language')->defaultCode())],
                actor: $context->actor,
                expectedRevision: null,
                currentRevision: 0,
                context: $context,
            ));

            $content = new Content($attributes);

            if ($owner !== null) {
                $content->forceFill(['managed_by' => $owner]);
            }

            return $this->gate()->sanctionNew(function () use ($content, $data, $owner, $context): Content {
                $content->save();

                if ($owner !== null) {
                    $this->recordAuthorityEvent($content, 'created', $owner, null, $owner, $context, null);
                    $this->writeOwnerPolicy((int) $content->id, $context->ownedFallback ?? ContentFallbackPolicy::Hide404, false);
                }

                return $this->gate()->sanction((int) $content->id, function () use ($content, $data): Content {
                    // Hook point: content is being saved (v1.0.0-beta.7.1.11).
                    do_action('cms.content.saving', $content, $data, hook_context(['content' => $content, 'data' => $data]));

                    $translation = $this->upsertTranslation($content, $data, isNew: true);

                    $this->syncTerms($content, $data['term_ids'] ?? null);
                    $this->upsertSlug($content, $translation);

                    $content->refresh();
                    $this->recordRevision($content, $translation->locale, $data);

                    return $content;
                });
            });
        });

        $this->afterWrite($content, ContentOperation::Create);
        do_action('cms.content.saved', $content, $data, hook_context(['content' => $content, 'data' => $data]));

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Content $content, array $data, ?int $expectedRevision = null, ?ContentWriteContext $context = null): Content
    {
        $context ??= ContentWriteContext::legacy();

        $fresh = $this->locked((int) $content->getKey(), $expectedRevision, $context, ContentOperation::Update,
            function (Content $locked, array $current) use ($data): array {
                $attributes = $this->contentAttributes($data, isNew: false, current: $locked);
                $translation = $this->prepareTranslation($locked, $data, isNew: false);
                $changes = ['row' => $this->rowChanges($current['row'], $this->normalizeRow($attributes))];
                $translationChanges = $this->translationChanges($current['translations'][$translation['locale']] ?? null, $translation['payload']);

                $changes['translations'] = $translationChanges === [] ? [] : [$translation['locale'] => $translationChanges];

                if (array_key_exists('term_ids', $data) && is_array($data['term_ids'])) {
                    $terms = $this->normalizeTermIds($data['term_ids']);

                    if ($terms !== $current['term_ids']) {
                        $changes['term_ids'] = $terms;
                    }
                }

                return [$changes, [$translation['locale']]];
            },
            function (Content $locked) use ($data): Content {
                do_action('cms.content.saving', $locked, $data, hook_context(['content' => $locked, 'data' => $data]));

                $locked->fill($this->contentAttributes($data, isNew: false, current: $locked));
                $locked->save();

                $translation = $this->upsertTranslation($locked, $data, isNew: false);

                if (array_key_exists('term_ids', $data)) {
                    $this->syncTerms($locked, $data['term_ids']);
                }

                $this->upsertSlug($locked, $translation);

                $locked->refresh();
                $this->recordRevision($locked, $translation->locale, $data);

                return $locked;
            },
        );

        $this->syncCaller($content, $fresh);
        do_action('cms.content.saved', $fresh, $data, hook_context(['content' => $fresh, 'data' => $data]));

        return $fresh;
    }

    /**
     * Change ONLY a content item's publication status (CORE-CONTENT-ADMIN-UX-1).
     *
     * The narrow, single-record status-transition authority reused by the admin
     * bulk "Change status" action so that a status change never rewrites the
     * title/body/slug/translations/terms/SEO/media the way {@see update()} would.
     * `published_at` follows the same rule as a normal save
     * ({@see resolvePublishedAt()}); the `cms.content.saving` / `cms.content.saved`
     * hooks fire with the changed field. Status is written verbatim; callers
     * validate the target against their allowed set before calling.
     */
    public function changeStatus(Content $content, string $status, ?int $expectedRevision = null, ?ContentWriteContext $context = null): Content
    {
        $context ??= ContentWriteContext::legacy();
        $data = ['status' => $status];

        $fresh = $this->locked((int) $content->getKey(), $expectedRevision, $context, ContentOperation::Status,
            function (Content $locked, array $current) use ($status): array {
                $proposed = $this->normalizeRow([
                    'status' => $status,
                    'published_at' => $this->resolvePublishedAt(['status' => $status], $status, $locked),
                ]);

                return [['row' => $this->rowChanges($current['row'], $proposed)], []];
            },
            function (Content $locked) use ($status, $data): Content {
                do_action('cms.content.saving', $locked, $data, hook_context(['content' => $locked, 'data' => $data]));

                $locked->status = $status;
                $locked->published_at = $this->resolvePublishedAt($data, $status, $locked);
                $locked->save();

                return $locked->refresh();
            },
        );

        $this->syncCaller($content, $fresh);
        do_action('cms.content.saved', $fresh, $data, hook_context(['content' => $fresh, 'data' => $data]));

        return $fresh;
    }

    public function delete(Content $content, ?int $expectedRevision = null, ?ContentWriteContext $context = null): bool
    {
        $context ??= ContentWriteContext::legacy();
        $deleted = false;

        $fresh = $this->locked((int) $content->getKey(), $expectedRevision, $context, ContentOperation::Delete,
            static fn (Content $locked, array $current): array => [[], array_keys($current['translations'])],
            function (Content $locked) use (&$deleted): Content {
                Slug::query()
                    ->where('reference_type', 'content')
                    ->where('reference_id', $locked->id)
                    ->delete();

                $deleted = (bool) $locked->delete();

                return $locked;
            },
        );

        $this->syncCaller($content, $fresh);

        return $deleted;
    }

    /**
     * Run several content writes for ONE content item as a single unit inside the
     * caller's transaction (extension orchestration, e.g. a multi-locale publish).
     *
     * The row is locked and the expected revision is checked ONCE here; nested
     * ContentManager writes for this content inside `$callback` still go through
     * the owner guard and RBAC, skip their own revision check, and the revision is
     * bumped exactly once at the end if anything was written. Must run on the
     * default connection; lock any extension rows AFTER this lock. A throw rolls
     * back every nested write, the revision bump and any marker change.
     *
     * @template T
     *
     * @param  Closure(Content): T  $callback
     * @return T
     */
    public function transaction(Content|int $content, ?int $expectedRevision, ContentWriteContext $context, Closure $callback): mixed
    {
        $id = $content instanceof Content ? (int) $content->getKey() : $content;

        return DB::transaction(function () use ($id, $expectedRevision, $context, $callback): mixed {
            if (isset($this->units[$id])) {
                throw new LogicException("A content write unit for content [{$id}] is already open.");
            }

            $locked = $this->lockRow($id);
            $revision = (int) $locked->revision;

            if ($expectedRevision === null && ! $context->isSystem()) {
                throw new ContentWriteDenied('revision_required', 'This change is missing the content revision it was based on. Reload the content and try again.', $id, $locked->managed_by);
            }

            if ($expectedRevision !== null && $expectedRevision !== $revision) {
                throw new ContentRevisionConflict($id, $expectedRevision, $revision);
            }

            $this->units[$id] = ['start' => $revision, 'dirty' => false];

            try {
                $result = $callback($locked);
                $dirty = $this->units[$id]['dirty'];
            } finally {
                unset($this->units[$id]);
            }

            if ($dirty) {
                $this->bumpRevision($id);
            }

            return $result;
        });
    }

    /**
     * Mark existing ordinary content as managed by the CALLING plugin.
     *
     * Only an extension context can claim, only for its own derived slug, only
     * when the plugin has a registered guard, and only for unmanaged content.
     * Requires the expected revision; RBAC is that of an update. Audited.
     */
    public function claimOwnership(Content|int $content, int $expectedRevision, ContentWriteContext $context, ?ContentFallbackPolicy $fallback = null): Content
    {
        // Adopted content had a Core body before, so Core keeps rendering it
        // while the adopter is inactive unless the adopter decides otherwise.
        $fallback ??= ContentFallbackPolicy::CoreRender;

        // Authorise the caller BEFORE the row is locked or inspected, so an
        // unauthorised plugin learns nothing about other owners or ids.
        $this->gate()->preauthorizeAdoption($context, $content instanceof Content ? (int) $content->getKey() : $content);
        $id = $content instanceof Content ? (int) $content->getKey() : $content;
        $claimant = $context->origin === ContentWriteContext::EXTENSION ? (string) $context->extension : '';

        $fresh = $this->locked($id, $expectedRevision, $context, ContentOperation::Claim,
            function (Content $locked) use ($claimant): array {
                if ($claimant === '') {
                    throw new ContentWriteDenied('claim_requires_extension', 'Only an active extension can take ownership of content.', (int) $locked->id);
                }

                if ($locked->managed_by !== null) {
                    throw new ContentWriteDenied('already_managed', 'This content is already managed by an extension.', (int) $locked->id, $locked->managed_by);
                }

                if (app('cms.content.guards')->guardFor($claimant) === null) {
                    throw new ContentWriteDenied('owner_inactive', 'An extension must register a content guard before it can take ownership of content.', (int) $locked->id, $claimant);
                }

                return [['row' => ['managed_by' => $claimant]], []];
            },
            function (Content $locked) use ($claimant, $context, $fallback): Content {
                $locked->forceFill(['managed_by' => $claimant])->save();
                $this->recordAuthorityEvent($locked, 'claimed', $claimant, null, $claimant, $context, null);
                $this->writeOwnerPolicy((int) $locked->id, $fallback, false, replace: true);

                return $locked->refresh();
            },
        );

        if ($content instanceof Content) {
            $this->syncCaller($content, $fresh);
        }

        return $fresh;
    }

    /**
     * Administrative recovery: clear the extension-ownership marker so the item
     * becomes ordinary content again (e.g. its plugin was removed).
     *
     * Requires `content.authority.recover` (strict), the expected revision and a
     * confirmation equal to the current owner slug; the owner guard is consulted
     * while that owner is active. Audited with the given reason. Never automatic.
     */
    public function detachOwnership(Content|int $content, int $expectedRevision, string $confirmation, string $reason, ?ContentWriteContext $context = null): Content
    {
        $context ??= ContentWriteContext::interactive();
        $id = $content instanceof Content ? (int) $content->getKey() : $content;
        $reason = trim($reason);
        $previous = null;

        $fresh = $this->locked($id, $expectedRevision, $context, ContentOperation::Detach,
            function (Content $locked) use ($confirmation, $reason, &$previous): array {
                if ($locked->managed_by === null) {
                    throw new ContentWriteDenied('not_managed', 'This content is not managed by an extension.', (int) $locked->id);
                }

                if (! hash_equals((string) $locked->managed_by, trim($confirmation))) {
                    throw new ContentWriteDenied('confirmation_mismatch', 'Type the extension name exactly to confirm the detachment.', (int) $locked->id, $locked->managed_by);
                }

                if ($reason === '' || mb_strlen($reason) > 255) {
                    throw new ContentWriteDenied('reason_required', 'Give a reason (up to 255 characters) for detaching this content.', (int) $locked->id, $locked->managed_by);
                }

                $previous = $locked->managed_by;

                return [['row' => ['managed_by' => null]], []];
            },
            function (Content $locked) use ($context, $reason, &$previous): Content {
                $locked->forceFill(['managed_by' => null])->save();
                $this->recordAuthorityEvent($locked, 'detached', (string) $previous, (string) $previous, null, $context, $reason);
                $this->deleteOwnerPolicy((int) $locked->id);

                return $locked->refresh();
            },
        );

        if ($content instanceof Content) {
            $this->syncCaller($content, $fresh);
        }

        return $fresh;
    }

    /**
     * Operator transfer (CORE-UNIFIED-OWNERSHIP-R1): compare-and-set from the
     * owner the operator expects ($expectedOwner, null = Core content) to
     * $newOwner (null = hand back to Core), under the row lock, revision
     * checked, audited. Authorised by {@see ContentWriteContext::operator()}
     * holding `content.authority.recover`; the current owner's guard is
     * consulted while it is active. A no-op transfer is refused, so a retry
     * never writes a second audit row.
     */
    public function transferOwnership(int $id, int $expectedRevision, ?string $expectedOwner, ?string $newOwner, ?ContentFallbackPolicy $fallback, ContentWriteContext $context, string $reason): Content
    {
        $reason = trim($reason);

        if ($context->operator === null) {
            throw new ContentWriteDenied('unauthorized_transfer', 'Only an operator can transfer content ownership.', $id);
        }

        if ($newOwner !== null && $fallback === null) {
            throw new ContentWriteDenied('fallback_required', 'Choose the fallback applied while the new owner is inactive.', $id);
        }

        $policy = $fallback ?? ContentFallbackPolicy::CoreRender;
        $this->gate()->preauthorizeTransfer($context, $id);

        return $this->locked($id, $expectedRevision, $context, ContentOperation::Transfer,
            function (Content $locked) use ($expectedOwner, $newOwner, $reason): array {
                if ($locked->getAttribute('managed_by') !== $expectedOwner) {
                    throw new ContentWriteDenied('ownership_changed', 'The owner changed since you looked; nothing was transferred.', (int) $locked->id, $locked->getAttribute('managed_by'));
                }

                if ($newOwner === $expectedOwner) {
                    throw new ContentWriteDenied('no_change', 'The content already has that owner.', (int) $locked->id, $locked->getAttribute('managed_by'));
                }

                if ($reason === '' || mb_strlen($reason) > 255) {
                    throw new ContentWriteDenied('reason_required', 'Give a reason (up to 255 characters) for this transfer.', (int) $locked->id, $locked->getAttribute('managed_by'));
                }

                return [['row' => ['managed_by' => $newOwner]], []];
            },
            function (Content $locked) use ($expectedOwner, $newOwner, $policy, $context, $reason): Content {
                $locked->forceFill(['managed_by' => $newOwner])->save();
                $this->recordAuthorityEvent($locked, $newOwner === null ? 'released' : 'transferred', (string) ($newOwner ?? $expectedOwner), $expectedOwner, $newOwner, $context, $reason);

                $newOwner === null
                    ? $this->deleteOwnerPolicy((int) $locked->id)
                    : $this->writeOwnerPolicy((int) $locked->id, $policy, true, replace: true);

                return $locked->refresh();
            },
        );
    }

    /**
     * The owner hands its content back to Core. The owner is derived HERE
     * from the call site (never trusted from an argument): the call must come
     * from the owning plugin's code; $owner must name that plugin and match
     * the LOCKED row. Not an edit of the content, so no RBAC/guard applies.
     */
    public function releaseOwnership(int $id, string $owner): bool
    {
        $caller = (new \TheNguyen\CMS\Content\Extension\CallSitePlugin(app(\TheNguyen\CMS\Services\ExtensionIdentityResolver::class), [
            __FILE__, (new \ReflectionClass(\TheNguyen\CMS\Content\Extension\ContentOwnershipRegistry::class))->getFileName(),
        ]))->resolve();

        if (! is_string($caller) || $caller === '' || $caller !== $owner) {
            throw new ContentWriteDenied('not_owner', 'Only the owning extension can release its content.', $id);
        }

        $actorId = \Illuminate\Support\Facades\Auth::id();

        $released = DB::transaction(function () use ($id, $owner, $actorId): bool {
            $locked = $this->lockRow($id);

            if ($locked->getAttribute('managed_by') !== $owner) {
                return false;
            }

            $this->gate()->sanction($id, function () use ($locked): void {
                $locked->forceFill(['managed_by' => null])->save();
            });
            $this->bumpRevision($id);
            ContentAuthorityAudit::record($id, 'released', $owner, $owner, null, 'extension', $actorId !== null ? (int) $actorId : null, (int) $locked->getAttribute('revision') + 1, null);
            $this->deleteOwnerPolicy($id);

            return true;
        });

        if ($released) {
            $this->afterWrite(Content::withTrashed()->findOrFail($id), ContentOperation::Transfer);
        }

        return $released;
    }

    private function writeOwnerPolicy(int $id, ContentFallbackPolicy $fallback, bool $operatorAssigned, bool $replace = false): void
    {
        if (! Schema::hasTable('cms_content_owner_policies')) {
            return; // upgrade window: managed rows without policy use the defaults
        }

        $values = ['fallback_policy' => $fallback->value, 'operator_assigned' => $operatorAssigned, 'updated_at' => now()];

        if ($replace) {
            DB::table('cms_content_owner_policies')->updateOrInsert(['content_id' => $id], $values + ['availability' => 'available', 'retry_after' => null, 'created_at' => now()]);

            return;
        }

        DB::table('cms_content_owner_policies')->insertOrIgnore($values + ['content_id' => $id, 'availability' => 'available', 'retry_after' => null, 'created_at' => now()]);
    }

    private function deleteOwnerPolicy(int $id): void
    {
        if (Schema::hasTable('cms_content_owner_policies')) {
            DB::table('cms_content_owner_policies')->where('content_id', $id)->delete();
        }
    }

    private function recordAuthorityEvent(Content $content, string $action, string $owner, ?string $previousOwner, ?string $newOwner, ContentWriteContext $context, ?string $reason): void
    {
        ContentAuthorityAudit::record(
            (int) $content->id, $action, $owner, $previousOwner, $newOwner, $context->auditOrigin(),
            $this->actorId($context), (int) $content->revision, $reason,
        );
    }

    /** The current revision of a content item (0 when unknown). */
    public function revisionOf(Content|int $content): int
    {
        $id = $content instanceof Content ? (int) $content->getKey() : $content;

        return (int) Content::withTrashed()->whereKey($id)->value('revision');
    }

    public function findBySlug(string $slug, string $locale = 'vi', ?string $type = null): ?Content
    {
        $query = Content::query()
            ->whereHas('translations', function ($q) use ($slug, $locale): void {
                $q->where('locale', $locale)->where('slug', $slug);
            });

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->first();
    }

    public function getTranslation(Content $content, string $locale = 'vi'): ?ContentTranslation
    {
        return $content->translations()->where('locale', $locale)->first();
    }

    // ---------------------------------------------------------------------
    // Locked write pipeline
    // ---------------------------------------------------------------------

    /**
     * @param  Closure(Content, array<string, mixed>): array{0: array<string, mixed>, 1: list<string>}  $plan
     * @param  Closure(Content): Content  $apply
     */
    private function locked(int $id, ?int $expectedRevision, ContentWriteContext $context, ContentOperation $operation, Closure $plan, Closure $apply): Content
    {
        $fresh = DB::transaction(function () use ($id, $expectedRevision, $context, $operation, $plan, $apply): Content {
            $locked = $this->lockRow($id);
            $current = $this->snapshot($locked);
            $unit = $this->units[$id] ?? null;
            $revision = $unit['start'] ?? (int) $locked->revision;

            [$changes, $locales] = $plan($locked, $current);

            $this->gate()->authorize(new ContentWriteIntent(
                operation: $operation,
                contentId: $id,
                type: (string) $locked->type,
                owner: $locked->managed_by,
                current: $current,
                changes: $changes,
                locales: $locales,
                actor: $context->actor,
                // Inside a unit the unit already checked the caller's revision.
                expectedRevision: $unit !== null ? $revision : $expectedRevision,
                currentRevision: $revision,
                context: $context,
            ));

            $result = $this->gate()->sanction($id, fn (): Content => $apply($locked));

            if ($unit !== null) {
                $this->units[$id]['dirty'] = true;
            } else {
                $this->bumpRevision($id);
            }

            return $result;
        });

        $this->afterWrite($fresh, $operation);

        return $fresh;
    }

    private function lockRow(int $id): Content
    {
        /** @var Content $locked */
        $locked = Content::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function bumpRevision(int $id): void
    {
        DB::table('cms_contents')->where('id', $id)->increment('revision');
    }

    /** Refresh the revision on the returned model and announce the commit. */
    private function afterWrite(Content $content, ContentOperation $operation): void
    {
        $content->setAttribute('revision', $this->revisionOf($content));
        $content->syncOriginalAttribute('revision');

        $id = (int) $content->getKey();

        DB::afterCommit(static function () use ($id, $operation): void {
            do_action('cms.content.committed', $id, $operation->value, hook_context(['content_id' => $id, 'operation' => $operation->value]));
        });
    }

    /** Keep a caller-held model in step with what was persisted. */
    private function syncCaller(Content $caller, Content $fresh): void
    {
        if ($caller !== $fresh) {
            $caller->setRawAttributes($fresh->getAttributes(), true);
            $caller->unsetRelation('translations');
            $caller->unsetRelation('terms');
        }
    }

    /**
     * Locked state of the row, its translations (rows locked in locale order) and
     * its term ids.
     *
     * @return array{row: array<string, mixed>, translations: array<string, array<string, mixed>>, term_ids: list<int>}
     */
    private function snapshot(Content $locked): array
    {
        $translations = [];

        foreach (ContentTranslation::query()->where('content_id', $locked->id)->orderBy('locale')->lockForUpdate()->get() as $row) {
            $translations[$row->locale] = $this->normalizeTranslation($row->only(self::TRANSLATION_FIELDS));
        }

        return [
            'row' => $this->normalizeRow($locked->getAttributes()) + ['managed_by' => $locked->managed_by],
            'translations' => $translations,
            'term_ids' => $this->normalizeTermIds($locked->terms()->pluck('cms_terms.id')->all()),
        ];
    }

    private const ROW_FIELDS = [
        'type', 'status', 'author_id', 'parent_id', 'template', 'featured_image', 'sort_order', 'comment_status',
        'views_count', 'comments_count', 'is_featured', 'show_page_title', 'published_at',
    ];

    private const TRANSLATION_FIELDS = ['title', 'slug', 'excerpt', 'content', 'meta_title', 'meta_description', 'meta_keywords'];

    /** @param  array<string, mixed>  $row */
    private function normalizeRow(array $row): array
    {
        $out = [];

        foreach (self::ROW_FIELDS as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];

            $out[$field] = match ($field) {
                'author_id', 'parent_id' => $value === null || $value === '' ? null : (int) $value,
                'sort_order', 'views_count', 'comments_count' => (int) $value,
                'is_featured', 'show_page_title' => (bool) $value,
                'published_at' => $value === null ? null : Carbon::parse($value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value)->format('Y-m-d H:i:s'),
                default => $value === null ? null : (string) $value,
            };
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row */
    private function normalizeTranslation(array $row): array
    {
        $out = [];

        foreach (self::TRANSLATION_FIELDS as $field) {
            $out[$field] = isset($row[$field]) ? (string) $row[$field] : null;
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return list<int>
     */
    private function normalizeTermIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    private function rowChanges(array $current, array $proposed): array
    {
        $changes = [];

        foreach ($proposed as $field => $value) {
            if (! array_key_exists($field, $current) || $current[$field] !== $value) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function translationChanges(?array $current, array $payload): array
    {
        $proposed = $this->normalizeTranslation($payload);

        if ($current === null) {
            return $proposed;
        }

        return array_filter($proposed, static fn ($value, $field) => ($current[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
    }

    private function actorId(ContentWriteContext $context): ?int
    {
        $id = $context->actor?->getAuthIdentifier();

        return $id === null ? null : (int) $id;
    }

    private function gate(): ContentWriteGate
    {
        return app('cms.content.gate');
    }

    // ---------------------------------------------------------------------
    // Field mapping
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contentAttributes(array $data, bool $isNew, ?Content $current = null, ?int $actorId = null): array
    {
        $status = $data['status'] ?? ($current->status ?? 'draft');
        $publishedAt = $this->resolvePublishedAt($data, $status, $current);

        return [
            'type' => $data['type'] ?? ($current->type ?? 'page'),
            'status' => $status,
            // A new item defaults to its creating actor as author, so ownership
            // rules recognise the creator (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
            'author_id' => $data['author_id'] ?? ($current->author_id ?? ($isNew ? $actorId : null)),
            'parent_id' => $data['parent_id'] ?? ($current->parent_id ?? null),
            'template' => $data['template'] ?? ($current->template ?? null),
            'featured_image' => $data['featured_image'] ?? ($current->featured_image ?? null),
            'sort_order' => $data['sort_order'] ?? ($current->sort_order ?? 0),
            'comment_status' => $data['comment_status'] ?? ($current->comment_status ?? 'closed'),
            'views_count' => (int) ($data['views_count'] ?? ($current->views_count ?? 0)),
            'comments_count' => (int) ($data['comments_count'] ?? ($current->comments_count ?? 0)),
            'is_featured' => (bool) ($data['is_featured'] ?? ($current->is_featured ?? false)),
            // Page-title visibility invariant (PB-FREE-LIBRARY-DESIGN-1-E-H1).
            // Base-entity presentation flag, default true for backward
            // compatibility; only overwritten when the form actually submits it,
            // so an update that omits it preserves the stored value.
            'show_page_title' => (bool) ($data['show_page_title'] ?? ($current->show_page_title ?? true)),
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePublishedAt(array $data, string $status, ?Content $current): ?CarbonInterface
    {
        if (array_key_exists('published_at', $data) && $data['published_at'] !== null) {
            return $data['published_at'] instanceof DateTimeInterface
                ? Carbon::instance($data['published_at'])
                : Carbon::parse((string) $data['published_at']);
        }

        if ($status === 'published') {
            if ($current && $current->published_at !== null) {
                return $current->published_at;
            }

            return Carbon::now();
        }

        return $current->published_at ?? null;
    }

    /**
     * The normalised translation payload a write would persist (read-only).
     *
     * @param  array<string, mixed>  $data
     * @return array{locale: string, payload: array<string, mixed>, existing: ?ContentTranslation}
     */
    private function prepareTranslation(Content $content, array $data, bool $isNew): array
    {
        $locale = (string) ($data['locale'] ?? app('cms.language')->defaultCode());
        $existing = $isNew
            ? null
            : $content->translations()->where('locale', $locale)->first();

        $title = (string) ($data['title'] ?? $existing?->title ?? '');

        $rawSlug = (string) ($data['slug'] ?? '');
        $baseSlug = $rawSlug !== '' ? $rawSlug : ($existing->slug ?? '');

        if ($baseSlug === '') {
            $baseSlug = $this->slugManager->generate($title, $locale);
        } else {
            $baseSlug = $this->slugManager->generate($baseSlug, $locale);
        }

        // Global per-locale uniqueness across pages/posts/categories/tags
        // (cms_slugs is the source of truth), ignoring this content's own row.
        $uniqueSlug = $this->slugManager->uniquePublicSlug(
            $baseSlug,
            $locale,
            referenceType: 'content',
            referenceId: $content->id,
        );

        $rawContent = $data['content'] ?? $existing?->content;

        return [
            'locale' => $locale,
            'existing' => $existing,
            'payload' => [
                'locale' => $locale,
                'title' => $title !== '' ? $title : null,
                'slug' => $uniqueSlug,
                'excerpt' => $data['excerpt'] ?? $existing?->excerpt,
                // Rich-editor (TinyMCE) HTML is sanitized on every write so the
                // stored body is always safe to render. Only the body is sanitized;
                // title/excerpt/meta are plain text handled elsewhere.
                'content' => $rawContent !== null ? $this->sanitizer->sanitize($rawContent) : null,
                'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
                'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
                'meta_keywords' => $data['meta_keywords'] ?? $existing?->meta_keywords,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertTranslation(Content $content, array $data, bool $isNew): ContentTranslation
    {
        $prepared = $this->prepareTranslation($content, $data, $isNew);

        return $this->persistTranslation($content, $prepared['locale'], $prepared['payload'], $prepared['existing']);
    }

    /**
     * Persist the (already slug-generated, sanitized and normalized) translation
     * payload. For posts, when `translation.modules.posts.write_driver = adapter`,
     * the persistence is delegated to the Phase 9.0D write adapter; for pages, when
     * `translation.modules.pages.write_driver = adapter`, to the Phase 9.1D write
     * adapter (both write through the content driver, joining THIS transaction).
     * Otherwise the legacy Eloquent write is used. Every other type always takes
     * the legacy path. This is the ONLY step the adapters change — slug
     * reservation, sanitization, hooks and cache behaviour above/around it are
     * untouched.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persistTranslation(Content $content, string $locale, array $payload, ?ContentTranslation $existing): ContentTranslation
    {
        if ($adapter = $this->postsWriteAdapter($content)) {
            return $adapter->persist($content, $locale, $payload, $existing);
        }

        if ($adapter = $this->pagesWriteAdapter($content)) {
            return $adapter->persist($content, $locale, $payload, $existing);
        }

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return $existing;
        }

        return $content->translations()->create($payload);
    }

    /**
     * The Posts write adapter (Phase 9.0D), or null when it must not be used.
     *
     * Returns the adapter ONLY for posts when `write_driver` is `adapter` — so
     * pages, terms, and the default configuration keep the unchanged legacy write.
     * Fully guarded: any resolution problem falls back to legacy.
     */
    private function postsWriteAdapter(Content $content): ?\TheNguyen\CMS\Translation\Adapters\PostTranslationWriteAdapter
    {
        if ($content->type !== 'post') {
            return null;
        }

        try {
            $adapter = app('cms.translation.posts_write_adapter');

            return $adapter->isActive() ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The Pages write adapter (Phase 9.1D), or null when it must not be used.
     *
     * Returns the adapter ONLY for pages when `write_driver` is `adapter` — so
     * posts, terms, and the default configuration keep the unchanged legacy write.
     * Fully guarded: any resolution problem falls back to legacy.
     */
    private function pagesWriteAdapter(Content $content): ?\TheNguyen\CMS\Translation\Adapters\PageTranslationWriteAdapter
    {
        if ($content->type !== 'page') {
            return null;
        }

        try {
            $adapter = app('cms.translation.pages_write_adapter');

            return $adapter->isActive() ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int>|null  $termIds
     */
    private function syncTerms(Content $content, ?array $termIds): void
    {
        if ($termIds === null) {
            return;
        }

        $content->terms()->sync(array_values(array_unique(array_map('intval', $termIds))));
    }

    private function upsertSlug(Content $content, ContentTranslation $translation): void
    {
        // Posts get the configurable post base (default "blog"); pages have none.
        $prefix = app('cms.permalink')->contentBase($content->type);

        $fullPath = $this->slugManager->makeFullPath($translation->slug, $prefix);

        Slug::query()->updateOrCreate(
            [
                'reference_type' => 'content',
                'reference_id' => $content->id,
                'locale' => $translation->locale,
            ],
            [
                'slug' => $translation->slug,
                'prefix' => $prefix,
                'full_path' => $fullPath,
                'is_primary' => true,
            ],
        );
    }

    /**
     * Record an immutable, locale-scoped revision for a Post (Phase 9.0F) or Page
     * (Phase 9.1F) write.
     *
     * Gated per entity type: posts by config('revisions.entities.posts'), pages by
     * config('revisions.entities.pages'). The platform master switch
     * config('revisions.enabled') still governs the recorder, so with either the
     * master or the per-entity flag off nothing is recorded and behaviour is
     * unchanged. Any other content type is never recorded. Called inside the write
     * transaction so a rollback removes the revision atomically; retention pruning
     * is deferred to after commit. Fault-isolated — a revision failure is reported
     * but never breaks the content write. ContentManager remains the sole write
     * authority; the revision layer only observes.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordRevision(Content $content, string $locale, array $data): void
    {
        // Per-type entity flag: posts (9.0F) and pages (9.1F) are the wired types;
        // any other content type is never recorded.
        $entityKey = match ($content->type) {
            'post' => 'posts',
            'page' => 'pages',
            default => null,
        };

        if ($entityKey === null) {
            return;
        }

        if (! (bool) config('revisions.entities.'.$entityKey, false)) {
            return;
        }

        try {
            $recorder = app('cms.revision.recorder');
            $recorder->record($content, $locale, $this->revisionContext($data));
            $recorder->pruneAfterCommit($content, $locale);
        } catch (\Throwable $e) {
            // Fault-isolated: revision recording must never break a content write.
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function revisionContext(array $data): RevisionContext
    {
        $authorId = $data['author_id'] ?? (auth()->check() ? auth()->id() : null);

        return $authorId !== null && $authorId !== ''
            ? RevisionContext::admin((int) $authorId)
            : RevisionContext::default();
    }
}
