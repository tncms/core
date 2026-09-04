<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\TestCase;
use TheNguyen\CMS\Services\ThemeAssetManifestResolver;
use TheNguyen\CMS\Services\ThemeManager;

/**
 * B2 — explicit parent/child theme authority (EG-6, v1.0.0-beta.7.1.24).
 *
 * Covers explicit parent resolution (never inferred), child→parent view
 * inheritance and override with no Default mix, required-view resolution across
 * the chain, owner-aware asset inheritance + explicit replacement, cycle /
 * self-parent / missing-parent rejection, and active-child parent lifecycle
 * protection.
 */
final class ChildThemeAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private string $parent = 'b2-parent';

    private string $child = 'b2-child';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeParent();
        $this->makeChild();
    }

    protected function tearDown(): void
    {
        foreach ([$this->parent, $this->child, 'b2-cycle-a', 'b2-cycle-b', 'b2-orphan'] as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }

        parent::tearDown();
    }

    private function makeParent(): void
    {
        $dir = base_path('themes/'.$this->parent);
        File::ensureDirectoryExists($dir.'/views/layouts');
        File::ensureDirectoryExists($dir.'/views/pages');
        File::ensureDirectoryExists($dir.'/views/posts');
        File::ensureDirectoryExists($dir.'/views/archives');
        File::ensureDirectoryExists($dir.'/assets/css');
        File::ensureDirectoryExists($dir.'/assets/js');

        File::put($dir.'/views/layouts/master.blade.php', 'PARENT-MASTER');
        File::put($dir.'/views/pages/page.blade.php', 'PARENT-PAGE');
        File::put($dir.'/views/posts/post.blade.php', 'PARENT-POST');
        File::put($dir.'/views/archives/index.blade.php', 'PARENT-ARCHIVE');
        File::put($dir.'/assets/css/parent.css', 'body{}');
        File::put($dir.'/assets/js/runtime.js', 'console.log(0)');

        File::put($dir.'/theme.json', json_encode([
            'name' => 'B2 Parent', 'slug' => $this->parent, 'version' => '1.0.0', 'author' => 'core-test',
            'assets' => [
                ['handle' => 'parent-css', 'src' => 'css/parent.css', 'primary' => true],
                ['handle' => 'parent-js', 'src' => 'js/runtime.js'],
            ],
        ]));
    }

    /** @param list<array<string,mixed>> $childAssets */
    private function makeChild(?array $childAssets = null): void
    {
        $dir = base_path('themes/'.$this->child);
        File::ensureDirectoryExists($dir.'/views/pages');
        File::ensureDirectoryExists($dir.'/assets/css');

        // Child overrides ONLY the page view; master/post/archive come from parent.
        File::put($dir.'/views/pages/page.blade.php', 'CHILD-PAGE');
        File::put($dir.'/assets/css/child.css', 'main{}');

        $childAssets ??= [
            ['handle' => 'child-css', 'src' => 'css/child.css', 'deps' => ['parent-css']],
        ];

        File::put($dir.'/theme.json', json_encode([
            'name' => 'B2 Child', 'slug' => $this->child, 'version' => '1.0.0', 'author' => 'core-test',
            'parent' => $this->parent,
            'assets' => $childAssets,
        ]));
    }

    private function themes(): ThemeManager
    {
        return app('cms.theme');
    }

    private function resolver(): ThemeAssetManifestResolver
    {
        return app('cms.theme_assets');
    }

    public function test_parent_chain_is_child_then_parent(): void
    {
        $resolved = $this->themes()->resolveParentChain($this->child);

        $this->assertSame([], $resolved['errors']);
        $this->assertSame([$this->child, $this->parent], $resolved['chain']);
    }

    public function test_child_view_hints_are_child_then_parent_no_default(): void
    {
        $this->themes()->registerViewNamespace($this->child);

        $hints = View::getFinder()->getHints()['theme'] ?? [];

        $this->assertCount(2, $hints);
        $this->assertStringContainsString($this->child, $hints[0]);
        $this->assertStringContainsString($this->parent, $hints[1]);
        foreach ($hints as $hint) {
            $this->assertStringNotContainsString('themes'.DIRECTORY_SEPARATOR.'default', $hint);
        }
    }

    public function test_child_overrides_page_parent_supplies_master(): void
    {
        $this->themes()->registerViewNamespace($this->child);

        $this->assertStringContainsString($this->child, View::getFinder()->find('theme::pages.page'));
        $this->assertStringContainsString($this->parent, View::getFinder()->find('theme::layouts.master'));
        $this->assertStringContainsString($this->parent, View::getFinder()->find('theme::posts.post'));
    }

    public function test_required_views_resolve_across_chain(): void
    {
        // Child lacks master/post/archive but the parent supplies them.
        $this->assertTrue($this->themes()->requiredViewsResolvable($this->child));
    }

    public function test_child_missing_required_view_with_no_parent_fallback_fails(): void
    {
        // Remove the parent master so the required 'layouts/master' is unresolvable.
        File::delete(base_path('themes/'.$this->parent.'/views/layouts/master.blade.php'));

        $this->assertFalse($this->themes()->requiredViewsResolvable($this->child));
    }

    public function test_asset_inheritance_is_owner_aware_and_ordered(): void
    {
        $manifest = $this->resolver()->resolve($this->child);
        $this->assertTrue($manifest->isValid(), implode(' | ', $manifest->errors));

        $by = [];
        $order = [];
        foreach ($manifest->declarations as $d) {
            $by[$d->handle] = $d;
            $order[] = $d->handle;
        }

        // Parent assets are inherited (owner=parent → parent-namespaced URL).
        $this->assertArrayHasKey('parent-css', $by);
        $this->assertSame($this->parent, $by['parent-css']->owner);
        $this->assertSame('/themes/'.$this->parent.'/css/parent.css', $by['parent-css']->url);

        // Child asset is owned by the child and depends on a parent handle.
        $this->assertSame($this->child, $by['child-css']->owner);
        $this->assertSame('/themes/'.$this->child.'/css/child.css', $by['child-css']->url);

        // Dependency order: parent-css before child-css.
        $this->assertLessThan(
            array_search('child-css', $order, true),
            array_search('parent-css', $order, true),
        );
    }

    public function test_explicit_replacement_drops_parent_and_rewires_dependents(): void
    {
        // Child replaces the parent's primary stylesheet; parent-js depends on it.
        $this->makeChild([
            ['handle' => 'child-theme', 'src' => 'css/child.css', 'replaces' => 'parent-css'],
        ]);

        // parent-js depends on parent-css — add that dep so we can prove rewiring.
        $parentDir = base_path('themes/'.$this->parent);
        File::put($parentDir.'/theme.json', json_encode([
            'name' => 'B2 Parent', 'slug' => $this->parent, 'version' => '1.0.0', 'author' => 'core-test',
            'assets' => [
                ['handle' => 'parent-css', 'src' => 'css/parent.css', 'primary' => true],
                ['handle' => 'parent-js', 'src' => 'js/runtime.js', 'deps' => ['parent-css']],
            ],
        ]));

        $manifest = $this->resolver()->resolve($this->child);
        $this->assertTrue($manifest->isValid(), implode(' | ', $manifest->errors));

        $handles = array_map(fn ($d) => $d->handle, $manifest->declarations);
        $this->assertNotContains('parent-css', $handles, 'replaced parent handle must be gone');
        $this->assertContains('child-theme', $handles);

        $by = [];
        foreach ($manifest->declarations as $d) {
            $by[$d->handle] = $d;
        }
        // parent-js's dependency on parent-css is rewired to the replacement.
        $this->assertContains('child-theme', $by['parent-js']->deps);
        $this->assertNotContains('parent-css', $by['parent-js']->deps);
    }

    public function test_self_parent_is_rejected(): void
    {
        File::put(base_path('themes/'.$this->child.'/theme.json'), json_encode([
            'name' => 'B2 Child', 'slug' => $this->child, 'version' => '1.0.0', 'author' => 'core-test',
            'parent' => $this->child,
        ]));

        $this->assertNotSame([], $this->themes()->resolveParentChain($this->child)['errors']);
        $this->assertFalse($this->resolver()->resolve($this->child)->isValid());
    }

    public function test_parent_cycle_is_rejected(): void
    {
        foreach (['b2-cycle-a' => 'b2-cycle-b', 'b2-cycle-b' => 'b2-cycle-a'] as $slug => $parent) {
            $dir = base_path('themes/'.$slug);
            File::ensureDirectoryExists($dir);
            File::put($dir.'/theme.json', json_encode([
                'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'core-test', 'parent' => $parent,
            ]));
        }

        $errors = $this->themes()->resolveParentChain('b2-cycle-a')['errors'];
        $this->assertNotSame([], $errors);
    }

    public function test_missing_parent_is_rejected(): void
    {
        File::put(base_path('themes/'.$this->child.'/theme.json'), json_encode([
            'name' => 'B2 Child', 'slug' => $this->child, 'version' => '1.0.0', 'author' => 'core-test',
            'parent' => 'b2-nonexistent-parent',
        ]));

        $this->assertNotSame([], $this->themes()->resolveParentChain($this->child)['errors']);
        $this->assertFalse($this->resolver()->resolve($this->child)->isValid());
    }

    public function test_active_child_protects_parent_from_deletion(): void
    {
        // Activate the child through the real lifecycle, then the parent must
        // report the active child as a dependent (deletion would be refused).
        $this->assertTrue($this->themes()->activate($this->child));

        $this->assertSame([$this->child], $this->themes()->activeDependentsOf($this->parent));
        // A non-parent theme has no active dependents.
        $this->assertSame([], $this->themes()->activeDependentsOf('b2-orphan'));
    }
}
