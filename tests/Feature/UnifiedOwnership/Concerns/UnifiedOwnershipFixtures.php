<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedOwnership\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use TheNguyen\CMS\ContentAuthority\ContentWriteException;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-UNIFIED-OWNERSHIP-R1 fixtures on top of the Line B authority fixtures
 * (seeded RBAC, synthetic plugins with a configurable guard). Adds an `Owner`
 * class to each synthetic plugin: plugin code exercising every ownership API
 * (`cms.content_ownership` and ContentManager) from inside the plugin.
 */
trait UnifiedOwnershipFixtures
{
    use ContentAuthorityFixtures;

    protected function ownerCode(string $slug): object
    {
        $file = $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Owner.php';
        $ns = $this->authorityNamespace($slug);

        if (! File::exists($file)) {
            File::put($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Illuminate\Contracts\Auth\Authenticatable;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\Models\Content;

/** Plugin code using the unified ownership APIs. */
final class Owner
{
    public function own(array \$data, ?ContentFallbackPolicy \$fallback = null): Content
    {
        return \$fallback === null
            ? app('cms.content_ownership')->createOwned(\$data)
            : app('cms.content_ownership')->createOwned(\$data, \$fallback);
    }

    public function createAs(array \$data, ?Authenticatable \$actor): Content
    {
        return app('cms.content')->create(\$data, ContentWriteContext::extension(\$actor)->claimingOwnership());
    }

    public function claim(Content|int \$content, int \$revision, ?Authenticatable \$actor = null, ?ContentFallbackPolicy \$fallback = null): Content
    {
        return app('cms.content')->claimOwnership(\$content, \$revision, ContentWriteContext::extension(\$actor), \$fallback);
    }

    public function update(Content \$content, array \$data, ?int \$revision, ?Authenticatable \$actor = null): Content
    {
        return app('cms.content')->update(\$content, \$data, \$revision, ContentWriteContext::extension(\$actor));
    }

    public function publish(Content \$content, ?int \$revision, ?Authenticatable \$actor = null): Content
    {
        return app('cms.content')->changeStatus(\$content, 'published', \$revision, ContentWriteContext::extension(\$actor));
    }

    public function availability(int \$id, ContentAvailability \$state, ?int \$retry = null): void
    {
        app('cms.content_ownership')->setAvailability(\$id, \$state, \$retry);
    }

    public function fallback(int \$id, ContentFallbackPolicy \$fallback): void
    {
        app('cms.content_ownership')->setFallback(\$id, \$fallback);
    }

    public function release(int \$id): void
    {
        app('cms.content_ownership')->release(\$id);
    }

    public function transfer(int \$id, string \$to): void
    {
        app('cms.content_ownership_admin')->assign(\$id, \$to, ContentFallbackPolicy::CoreRender, null, auth()->user() ?? new \App\Models\User);
    }

    public function operatorContext(Authenticatable \$operator): ContentWriteContext
    {
        return ContentWriteContext::operator(\$operator);
    }
}

PHP);
        }

        $fqcn = $this->loadAuthorityClass($slug, 'Owner');

        return new $fqcn;
    }

    /** A user allowed to adopt: explicit grant on a non-super role + edit-others. */
    protected function adopter(array $extra = []): User
    {
        return $this->userWithRole('editor', ['content.ownership.adopt', 'posts.edit_others', 'pages.edit_others', ...$extra]);
    }

    protected function denial(callable $write): string
    {
        try {
            $write();
        } catch (ContentWriteException $e) {
            return $e->errorCode();
        } catch (\TheNguyen\CMS\Content\Extension\ContentOwnershipException $e) {
            return 'ownership:'.$e->reason;
        } catch (\LogicException $e) {
            return 'logic';
        }

        return 'ALLOWED';
    }

    /** Full persisted state of a content item for "nothing changed" checks. */
    protected function persisted(int $id): string
    {
        return (string) json_encode([
            DB::table('cms_contents')->where('id', $id)->first(),
            DB::table('cms_content_owner_policies')->where('content_id', $id)->first(),
            DB::table('cms_content_authority_events')->where('content_id', $id)->count(),
        ]);
    }

    protected function events(int $id): array
    {
        return DB::table('cms_content_authority_events')->where('content_id', $id)->orderBy('id')
            ->get(['action', 'owner', 'previous_owner', 'new_owner', 'origin', 'actor_id', 'reason', 'correlation_id'])
            ->map(static fn ($r) => (array) $r)->all();
    }

    protected function coreArticle(string $title, ?User $author = null, array $extra = []): Content
    {
        return $this->makeContent(['title' => $title, 'status' => 'published'] + $extra, $author);
    }
}
