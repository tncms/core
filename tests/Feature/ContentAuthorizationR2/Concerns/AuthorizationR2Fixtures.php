<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthorizationR2\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\ContentActionAuthorization\Concerns\ActionFixtures;
use TheNguyen\CMS\Models\Content;

/**
 * Fixtures for CORE-CONTENT-AUTHORIZATION-R2 (private drafts, extension-owned content).
 *
 * Each synthetic plugin (a real directory under the temp plugins root, see
 * ContentAuthorityFixtures) gets a `Decider`: the plugin's own code asking
 * `cms.content.actions`, so any owner identity is derived from a genuine plugin
 * file exactly as at runtime.
 */
trait AuthorizationR2Fixtures
{
    use ActionFixtures;

    protected function setUpR2Site(): void
    {
        $this->seedActionSite();
        $this->useAuthorityFixturePlugins();
    }

    /** Write + activate a plugin with a guard (mode) and a Decider; returns [Plugin, Decider]. */
    protected function r2Plugin(string $slug, string $guardMode = 'own-only'): array
    {
        $this->writeAuthorityPlugin($slug);
        $this->writeDecider($slug);
        $plugin = $this->guardedPlugin($slug, $guardMode);

        return [$plugin, $this->decider($slug)];
    }

    protected function decider(string $slug): object
    {
        $fqcn = $this->loadAuthorityClass($slug, 'Decider');

        return new $fqcn;
    }

    protected function writeDecider(string $slug): void
    {
        $file = $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Decider.php';

        if (File::exists($file)) {
            return;
        }

        $ns = $this->authorityNamespace($slug);
        File::put($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Illuminate\Contracts\Auth\Authenticatable;
use TheNguyen\CMS\ContentAuthority\ContentActionContext;
use TheNguyen\CMS\ContentAuthority\ContentActionDecision;

/** The plugin's own code asking Core for a content action decision. */
final class Decider
{
    public function decide(?Authenticatable \$actor, string \$action, mixed \$subject, ?string \$locale = null, bool \$privateDraft = false, bool \$asOwner = false): ContentActionDecision
    {
        \$context = \$this->context(\$locale, \$privateDraft, \$asOwner);

        return app('cms.content.actions')->decision(\$actor, \$action, \$subject, \$context);
    }

    public function context(?string \$locale = null, bool \$privateDraft = false, bool \$asOwner = false): ContentActionContext
    {
        \$context = \$locale === null ? ContentActionContext::none() : ContentActionContext::forLocale(\$locale);

        if (\$privateDraft) {
            \$context = \$context->privateDraft();
        }

        return \$asOwner ? \$context->asOwningExtension() : \$context;
    }
}

PHP);
    }

    /** Content managed by an extension, created through the owner's sanctioned Core write. */
    protected function managedPost(object $owner, User $author, string $status = 'draft', array $extra = []): Content
    {
        $previous = auth()->user();
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $content = $owner->createManaged($extra + ['type' => 'post', 'status' => $status, 'locale' => 'en', 'title' => 'Owned '.$status, 'content' => '<p>Body</p>'], $admin);
        // Authorship is fixture data here (the creator is the signed-in admin).
        DB::table('cms_contents')->where('id', $content->id)->update(['author_id' => $author->id]);
        $previous !== null ? $this->actingAs($previous) : auth()->logout();

        return $content->refresh();
    }

    /** An actor with Core edit (no publish) on posts and pages. */
    protected function editOnly(): User
    {
        return $this->userWithPermissions('r2-edit-only-'.uniqid(), ['admin.access', 'posts.view', 'posts.edit', 'pages.view', 'pages.edit']);
    }

    protected function publisher(): User
    {
        return $this->userWithPermissions('r2-publisher-'.uniqid(), ['admin.access', 'posts.view', 'posts.edit', 'posts.publish', 'pages.view', 'pages.edit', 'pages.publish']);
    }

    protected function revoke(User $user, string $permission): void
    {
        $roles = DB::table('cms_role_user')->where('user_id', $user->id)->pluck('role_id');
        $id = DB::table('cms_permissions')->where('slug', $permission)->value('id');
        DB::table('cms_role_permissions')->whereIn('role_id', $roles)->where('permission_id', $id)->delete();
        // The next request starts with an empty grant memo (request-scoped PermissionManager).
        app()->forgetInstance('cms.permission');
        app()->forgetInstance('cms.content.gate');
        app()->forgetInstance('cms.content.actions');
    }
}
