<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Concerns;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use TheNguyen\CMS\ContentAuthority\ContentRevisionConflict;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;
use TheNguyen\CMS\ContentAuthority\ContentWriteException;
use TheNguyen\CMS\ContentAuthority\ManagedContentEditUrl;
use TheNguyen\CMS\Models\Content;

/**
 * Shared content list affordances for PostResource and PageResource
 * (CORE-CONTENT-ADMIN-UX-1).
 *
 * Both resources manage the same {@see Content} model (discriminated by `type`),
 * so the bulk "Change status" / "Delete selected" actions and the toggleable
 * featured-image column live here once instead of being duplicated in two
 * tables. All mutation flows through the Core {@see \TheNguyen\CMS\Services\ContentManager}
 * single-record authority (`cms.content`) so the content lifecycle, plugin
 * hooks, slug cleanup and public-cache invalidation are never bypassed — there
 * is no query-builder mass update()/delete() anywhere in this trait.
 *
 * Authorization is enforced PER RECORD at execution time via the including
 * resource's existing {@see canEdit()} / {@see canDelete()} authority, so a
 * direct Livewire call or a mixed-authority selection can never mutate a record
 * the current user may not touch — the toolbar button visibility is a
 * convenience only, never the security boundary.
 *
 * Atomicity policy: per record. Each status change / delete is individually
 * transactional inside ContentManager; the batch is best-effort partial-success.
 * A denied, non-Content, unchanged or failing record never aborts the others,
 * and the completion notification reports the exact changed / skipped / denied /
 * failed tally — success is never reported falsely.
 */
trait HasContentBulkActions
{
    /**
     * The canonical, localized publication-status options shared by the edit
     * form Select and the bulk "Change status" action. Single source of truth
     * for the statuses Posts and Pages support (draft, published, pending,
     * private — verified from the resource forms and the status badge column).
     *
     * @return array<string, string>
     */
    public static function contentStatusOptions(): array
    {
        return [
            'draft' => tn_trans('Draft'),
            'published' => tn_trans('Published'),
            'pending' => tn_trans('Pending'),
            'private' => tn_trans('Private'),
        ];
    }

    /**
     * The permission prefix (`posts` / `pages`) the including resource gates its
     * abilities on. Matches the slugs already used by canEdit()/canDelete().
     */
    abstract protected static function contentPermissionPrefix(): string;

    // ---------------------------------------------------------------------
    // Authority mirror (CORE-STRUCTURED-CONTENT-AUTHORITY-R1). UI only: the
    // ContentManager re-checks every rule on the locked row at write time.
    // ---------------------------------------------------------------------

    /** Whether the current user may publish / unpublish / change published content. */
    public static function mayPublishContent(): bool
    {
        return app('cms.permission')->userCanStrict(auth()->user(), static::contentPermissionPrefix().'.publish');
    }

    /** Base edit permission plus ownership (own record, or `{prefix}.edit_others`). */
    public static function mayEditContent(Model $record): bool
    {
        return cms_can(static::contentPermissionPrefix().'.edit')
            && (static::isOwnContent($record) || app('cms.permission')->userCanStrict(auth()->user(), static::contentPermissionPrefix().'.edit_others'));
    }

    /** Base delete permission plus ownership (own record, or `{prefix}.delete_others`). */
    public static function mayDeleteContent(Model $record): bool
    {
        return cms_can(static::contentPermissionPrefix().'.delete')
            && (static::isOwnContent($record) || app('cms.permission')->userCanStrict(auth()->user(), static::contentPermissionPrefix().'.delete_others'));
    }

    protected static function isOwnContent(Model $record): bool
    {
        $author = $record->getAttribute('author_id');

        return $author !== null && auth()->check() && (string) $author === (string) auth()->id();
    }

    /**
     * Status options offered to the current user: `published` only with publish
     * rights (kept for display on an already-published record, whose status
     * field is then locked — see {@see contentStatusLocked()}).
     *
     * @return array<string, string>
     */
    public static function contentStatusOptionsFor(?Model $record): array
    {
        $options = static::contentStatusOptions();

        if (! static::mayPublishContent() && $record?->getAttribute('status') !== 'published') {
            unset($options['published']);
        }

        return $options;
    }

    /** A published record's status is read-only for users without publish rights. */
    public static function contentStatusLocked(?Model $record): bool
    {
        return $record?->getAttribute('status') === 'published' && ! static::mayPublishContent();
    }

    /** Row URL: the native editor, or the managing extension's editor for managed content. */
    public static function contentRecordUrl(Content $record): ?string
    {
        if (! static::canEdit($record)) {
            return null;
        }

        return $record->managed_by !== null
            ? ManagedContentEditUrl::for($record)
            : static::getUrl('edit', ['record' => $record]);
    }

    protected static function contentEditAction(): EditAction
    {
        return EditAction::make()
            ->url(static fn (Content $record): ?string => static::contentRecordUrl($record))
            ->visible(static fn (Content $record): bool => static::contentRecordUrl($record) !== null);
    }

    /** Row delete through ContentManager::delete() with the revision captured when the modal opened. */
    protected static function contentDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->schema([Hidden::make('revision')])
            ->fillForm(static fn (Content $record): array => ['revision' => (int) $record->revision])
            ->using(static fn (array $data, Content $record): bool => static::deleteContentThroughService(
                $record,
                is_numeric($data['revision'] ?? null) ? (int) $data['revision'] : null,
            ));
    }

    /**
     * Delete through the Core authority; a refusal is reported and halts the
     * action (no false success notification).
     */
    public static function deleteContentThroughService(Content $record, ?int $expectedRevision): bool
    {
        try {
            return app('cms.content')->delete($record, $expectedRevision, ContentWriteContext::interactive());
        } catch (ContentWriteException $e) {
            static::notifyContentWriteFailure($e);

            throw new Halt;
        }
    }

    /** Administrative detach of extension-managed content (strict `content.authority.recover`). */
    protected static function contentDetachAction(): Action
    {
        return Action::make('detachOwnership')
            ->label(tn_trans('Detach from extension'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->visible(static fn (Content $record): bool => $record->managed_by !== null
                && app('cms.permission')->userCanStrict(auth()->user(), 'content.authority.recover'))
            ->modalHeading(tn_trans('Detach from extension'))
            ->modalDescription(tn_trans('The extension will no longer protect this content and it becomes ordinary content. Its structured data is kept by the extension. This is recorded in the audit log.'))
            ->schema([
                Hidden::make('revision'),
                TextInput::make('confirmation')
                    ->label(static fn (Content $record): string => tn_trans('Type :owner to confirm', ['owner' => (string) $record->managed_by]))
                    ->required(),
                Textarea::make('reason')
                    ->label(tn_trans('Reason'))
                    ->required()
                    ->maxLength(255),
            ])
            ->fillForm(static fn (Content $record): array => ['revision' => (int) $record->revision])
            ->action(static function (array $data, Content $record): void {
                try {
                    app('cms.content')->detachOwnership(
                        $record,
                        (int) ($data['revision'] ?? -1),
                        (string) ($data['confirmation'] ?? ''),
                        (string) ($data['reason'] ?? ''),
                        ContentWriteContext::interactive(),
                    );

                    Notification::make()->title(tn_trans('The content was detached from its extension.'))->success()->send();
                } catch (ContentWriteException $e) {
                    static::notifyContentWriteFailure($e);
                }
            });
    }

    public static function notifyContentWriteFailure(ContentWriteException $e): void
    {
        Notification::make()
            ->title($e instanceof ContentRevisionConflict ? tn_trans('Changed by someone else') : tn_trans('Not saved'))
            ->body(tn_trans($e->getMessage()))
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * id => revision map of the selected records, captured when the bulk modal
     * opens so a stale selection is reported as a conflict.
     */
    protected static function capturedRevisions(Collection $records): string
    {
        return (string) json_encode($records->mapWithKeys(
            static fn ($record): array => $record instanceof Content ? [(string) $record->getKey() => (int) $record->revision] : [],
        )->all());
    }

    /** @return array<string, int> */
    protected static function revisionsFromState(mixed $state): array
    {
        $map = is_string($state) ? json_decode($state, true) : null;

        return is_array($map) ? array_map('intval', array_filter($map, 'is_numeric')) : [];
    }

    /**
     * Toolbar (bulk) actions for the content list. Adding these to the table
     * also enables Filament's row-selection checkboxes and select-all.
     *
     * @return array<int, BulkAction>
     */
    protected static function contentBulkActions(): array
    {
        return [
            static::bulkChangeStatusAction(),
            static::bulkDeleteContentAction(),
        ];
    }

    /**
     * Bulk "Change status": pick a target status in the modal, then apply it to
     * every authorized selected record through ContentManager::changeStatus()
     * (which preserves all content/translation/slug/SEO/taxonomy/media fields and
     * invalidates the public cache). Idempotent: a record already at the target
     * status is skipped, not re-saved.
     */
    protected static function bulkChangeStatusAction(): BulkAction
    {
        return BulkAction::make('changeStatus')
            ->label(tn_trans('Change status'))
            ->icon('heroicon-o-arrow-path')
            ->visible(static fn (): bool => cms_can(static::contentPermissionPrefix().'.edit'))
            ->schema([
                Select::make('status')
                    ->label(tn_trans('Status'))
                    ->options(static fn (): array => static::contentStatusOptionsFor(null))
                    ->required()
                    ->native(false),
                Hidden::make('revisions'),
            ])
            ->fillForm(static fn (Collection $records): array => ['revisions' => static::capturedRevisions($records)])
            ->modalHeading(tn_trans('Change status'))
            ->modalDescription(tn_trans('Apply the selected status to the selected items. Titles, content, translations, slug, SEO, taxonomy and featured image are preserved.'))
            ->modalSubmitActionLabel(tn_trans('Set status'))
            ->deselectRecordsAfterCompletion()
            ->action(static function (array $data, Collection $records): void {
                $target = is_string($data['status'] ?? null) ? $data['status'] : '';

                if (! array_key_exists($target, static::contentStatusOptions())) {
                    Notification::make()
                        ->title(tn_trans('The selected status is not valid.'))
                        ->danger()
                        ->send();

                    return;
                }

                $changed = 0;
                $skipped = 0;
                $denied = 0;
                $conflicts = 0;
                $failed = 0;
                $revisions = static::revisionsFromState($data['revisions'] ?? null);

                foreach ($records as $record) {
                    if (! $record instanceof Content) {
                        $failed++;

                        continue;
                    }

                    if (! static::canEdit($record)) {
                        $denied++;

                        continue;
                    }

                    // Idempotent: already at the target status — no needless write,
                    // revision or cache flush.
                    if ($record->status === $target) {
                        $skipped++;

                        continue;
                    }

                    try {
                        app('cms.content')->changeStatus($record, $target, $revisions[(string) $record->getKey()] ?? null, ContentWriteContext::interactive());
                        $changed++;
                    } catch (ContentRevisionConflict) {
                        $conflicts++;
                    } catch (ContentWriteDenied) {
                        $denied++;
                    } catch (\Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }

                static::sendBulkOutcome(
                    $changed > 0
                        ? tn_trans('Updated :count item(s) to :status.', [
                            'count' => $changed,
                            'status' => static::contentStatusOptions()[$target],
                        ])
                        : tn_trans('No changes were applied.'),
                    $changed > 0,
                    $skipped,
                    $denied,
                    $failed,
                    $conflicts,
                );
            });
    }

    /**
     * Bulk "Delete selected": delete every authorized selected record through
     * ContentManager::delete() — the Core domain authority that removes the
     * record's cms_slugs rows and soft-deletes the content, firing the
     * cms.content.deleting/deleted lifecycle hooks and invalidating the public
     * cache. No mass SQL.
     */
    protected static function bulkDeleteContentAction(): BulkAction
    {
        return BulkAction::make('deleteContent')
            ->label(tn_trans('Delete selected'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(static fn (): bool => cms_can(static::contentPermissionPrefix().'.delete'))
            ->requiresConfirmation()
            ->modalHeading(tn_trans('Delete selected'))
            ->modalDescription(tn_trans('The selected items will be deleted and removed from public listings and routes.'))
            ->schema([Hidden::make('revisions')])
            ->fillForm(static fn (Collection $records): array => ['revisions' => static::capturedRevisions($records)])
            ->deselectRecordsAfterCompletion()
            ->action(static function (array $data, Collection $records): void {
                $deleted = 0;
                $denied = 0;
                $conflicts = 0;
                $failed = 0;
                $revisions = static::revisionsFromState($data['revisions'] ?? null);

                foreach ($records as $record) {
                    if (! $record instanceof Content) {
                        $failed++;

                        continue;
                    }

                    if (! static::canDelete($record)) {
                        $denied++;

                        continue;
                    }

                    try {
                        app('cms.content')->delete($record, $revisions[(string) $record->getKey()] ?? null, ContentWriteContext::interactive());
                        $deleted++;
                    } catch (ContentRevisionConflict) {
                        $conflicts++;
                    } catch (ContentWriteDenied) {
                        $denied++;
                    } catch (\Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }

                static::sendBulkOutcome(
                    $deleted > 0
                        ? tn_trans('Deleted :count item(s).', ['count' => $deleted])
                        : tn_trans('No items were deleted.'),
                    $deleted > 0,
                    0,
                    $denied,
                    $failed,
                    $conflicts,
                );
            });
    }

    /**
     * The toggleable featured-image column shared by both content tables.
     *
     * `cms_contents.featured_image` stores the image URL directly on the record
     * (no media relation), so the thumbnail is already loaded with the row —
     * there is no per-row media query and thus no N+1. File-existence checking is
     * disabled so no per-row Storage stat runs either. Toggleable with the same
     * mechanism as the slug column (visible by default; Filament persists the
     * per-user toggle). A graceful inline placeholder renders when a record has
     * no featured image.
     */
    protected static function featuredImageColumn(): ImageColumn
    {
        return ImageColumn::make('featured_image')
            ->label(tn_trans('Image'))
            ->toggleable()
            ->checkFileExistence(false)
            ->height(40)
            ->width(40)
            ->defaultImageUrl(static fn (): string => static::featuredImagePlaceholder())
            // Filament renders extraImgAttributes values unescaped, so the
            // author-controlled title must be escaped here (H-Z1-M2).
            ->extraImgAttributes(static fn (Content $record): array => [
                'style' => 'object-fit: cover; border-radius: 6px;',
                'alt' => e($record->translatedTitle(editing_locale())),
                'loading' => 'lazy',
            ]);
    }

    /**
     * Compose and send the per-batch outcome notification with an accurate
     * tally. Never claims success when nothing changed.
     */
    private static function sendBulkOutcome(string $title, bool $success, int $skipped, int $denied, int $failed, int $conflicts = 0): void
    {
        $lines = [];

        if ($conflicts > 0) {
            $lines[] = tn_trans('Changed by someone else (reload and retry): :count.', ['count' => $conflicts]);
        }

        if ($skipped > 0) {
            $lines[] = tn_trans('Already set: :count.', ['count' => $skipped]);
        }

        if ($denied > 0) {
            $lines[] = tn_trans('Not permitted: :count.', ['count' => $denied]);
        }

        if ($failed > 0) {
            $lines[] = tn_trans('Failed: :count.', ['count' => $failed]);
        }

        $notification = Notification::make()->title($title);

        if ($lines !== []) {
            $notification->body(implode(' ', $lines));
        }

        ($success ? $notification->success() : $notification->warning())->send();
    }

    /**
     * A neutral, dependency-free inline SVG placeholder for records without a
     * featured image (keeps the column shape stable and accessible).
     */
    private static function featuredImagePlaceholder(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">'
            .'<rect width="40" height="40" rx="6" fill="#e5e7eb"/>'
            .'<path d="M11 27l6-7 4 5 3-3 5 5z" fill="#9ca3af"/>'
            .'<circle cx="15" cy="15" r="3" fill="#9ca3af"/>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
