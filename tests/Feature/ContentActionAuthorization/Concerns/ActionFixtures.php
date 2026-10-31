<?php

declare(strict_types=1);

namespace Tests\Feature\ContentActionAuthorization\Concerns;

use App\Filament\Admin\Resources\PageResource;
use App\Filament\Admin\Resources\PostResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentActionAuthority;
use TheNguyen\CMS\ContentAuthority\ContentActionContext;
use TheNguyen\CMS\ContentAuthority\ContentActionDecision;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteException;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * Fixtures for CORE-CONTENT-ACTION-AUTHORIZATION-R1: the public decision API
 * and the real Core Admin write authority it must match.
 */
trait ActionFixtures
{
    use ContentAuthorityFixtures;

    protected function actions(): ContentActionAuthority
    {
        return app('cms.content.actions');
    }

    protected function decide(?User $actor, ContentAction|string $action, mixed $subject, ?string $locale = null): ContentActionDecision
    {
        return $this->actions()->decision($actor, $action, $subject, $locale === null ? null : ContentActionContext::forLocale($locale));
    }

    /** Core languages (vi default, en) plus an inactive fr. */
    protected function seedActionSite(): void
    {
        $this->seedSite();

        Language::query()->create([
            'code' => 'fr', 'locale' => 'fr_FR', 'name' => 'French', 'native_name' => 'Français',
            'direction' => 'ltr', 'is_default' => false, 'is_active' => false, 'sort_order' => 9,
        ]);

        app()->forgetInstance('cms.language');
    }

    /** A role that is not part of Core: edits anyone's drafts, never publishes. */
    protected function contributor(): User
    {
        return $this->userWithPermissions('contributor', [
            'admin.access', 'posts.view', 'posts.create', 'posts.edit', 'posts.edit_others',
            'pages.view', 'pages.create', 'pages.edit', 'pages.edit_others',
        ]);
    }

    /** @param  list<string>  $permissions */
    protected function userWithPermissions(string $slug, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        $user = User::factory()->create();
        $role->users()->attach($user->id);

        return $user;
    }

    protected function noPermissionUser(): User
    {
        return $this->userWithRole('subscriber');
    }

    /**
     * What the real Core Admin authority does for the same request: the write
     * is attempted through ContentManager as the actor (interactive context,
     * correct revision) and always rolled back.
     */
    protected function adminAllows(?User $actor, Content $content, ContentAction $action, ?string $locale = null): bool
    {
        $content = Content::withTrashed()->findOrFail($content->id);
        $resource = $content->type === 'page' ? PageResource::class : PostResource::class;

        if ($actor === null) {
            return false;
        }

        $previous = auth()->user();
        $this->actingAs($actor);

        try {
            // The Admin resource query (default scope: no trashed rows) is the only
            // way the Admin reaches a record.
            $reachable = $resource::getEloquentQuery()->whereKey($content->id)->exists();

            // The editor only edits a locale its resolver accepts from ?locale=.
            $editable = $locale === null
                || app('cms.locale_preference')->resolveEditingLocale(Request::create('/admin?locale='.rawurlencode($locale)), $actor) === $locale;

            if ($action === ContentAction::View) {
                return $reachable && $editable && $resource::canViewAny();
            }
        } finally {
            $previous !== null ? $this->actingAs($previous) : auth()->logout();
        }

        if (! $reachable || ! $editable) {
            return false;
        }

        $locale ??= 'en';

        // Contract semantics, not an Admin rule: create_translation targets a
        // missing translation, edit_translation an existing one.
        $exists = $content->translations()->where('locale', $locale)->exists();

        if (($action === ContentAction::CreateTranslation && $exists) || ($action === ContentAction::EditTranslation && ! $exists)) {
            return false;
        }

        $previous = auth()->user();
        $this->actingAs($actor);
        DB::beginTransaction();

        try {
            $ctx = ContentWriteContext::interactive($actor);
            $published = $content->status === 'published';

            match ($action) {
                ContentAction::Publish => $published
                    ? $this->contents()->update($content, ['locale' => $locale, 'title' => 'Republished'], $content->revision, $ctx)
                    : $this->contents()->changeStatus($content, 'published', $content->revision, $ctx),
                ContentAction::Unpublish => $this->contents()->changeStatus($content, $published ? 'draft' : 'published', $content->revision, $ctx),
                default => $this->contents()->update($content, ['locale' => $locale, 'title' => 'Edited '.$locale], $content->revision, $ctx),
            };

            return true;
        } catch (ContentWriteException) {
            return false;
        } finally {
            DB::rollBack();
            $previous !== null ? $this->actingAs($previous) : auth()->logout();
        }
    }

    /** Checksum of every content/translation/slug/revision/permission row. */
    protected function databaseFingerprint(): string
    {
        $parts = [];

        foreach (['cms_contents', 'cms_content_translations', 'cms_slugs', 'cms_revisions', 'cms_role_permissions', 'cms_role_user', 'cms_content_owner_policies', 'cms_content_authority_events', 'cms_content_terms'] as $table) {
            $parts[$table] = DB::table($table)->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return hash('sha256', (string) json_encode($parts));
    }
}
