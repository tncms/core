<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority;

use App\Filament\Admin\Resources\PageResource\Pages\EditPage;
use App\Filament\Admin\Resources\PostResource\Pages\CreatePost;
use App\Filament\Admin\Resources\PostResource\Pages\EditPost;
use App\Filament\Admin\Resources\PostResource\Pages\ListPosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ManagedContentEditUrl;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1 — the Filament admin adapters (real
 * Livewire components): expected revision on edit forms, conflict reporting,
 * omitted-field semantics, per-record bulk outcome, managed-content redirect
 * and the record-aware form schema context (T-A4, T-B1, T-C1, T-C2).
 */
final class ContentAdminAuthorityTest extends TestCase
{
    use ContentAuthorityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    private function enTitle(Content $content): ?string
    {
        return $content->translations()->where('locale', 'en')->value('title');
    }

    public function test_edit_form_save_against_a_changed_record_is_a_conflict_not_an_overwrite(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $post = $this->makeContent(['title' => 'Opened'], $admin);

        $page = Livewire::withQueryParams(['locale' => 'en'])->test(EditPost::class, ['record' => $post->id]);

        // Someone else saves while the form is open.
        $this->contents()->update($post->fresh(), ['locale' => 'en', 'title' => 'Theirs'], $post->revision, ContentWriteContext::interactive());

        $page->fillForm(['title' => 'Mine'])->call('save')->assertNotified(tn_trans('Changed by someone else'));
        $this->assertSame('Theirs', $this->enTitle($post));

        // The captured revision is server-locked: the browser cannot refresh it.
        try {
            $page->set('contentRevision', $post->fresh()->revision);
            $this->fail('The locked revision property was writable from the client.');
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('locked', $e->getMessage());
        }

        // A freshly opened form saves normally and advances its captured revision.
        Livewire::withQueryParams(['locale' => 'en'])->test(EditPost::class, ['record' => $post->id])
            ->fillForm(['title' => 'Mine after reload'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Mine after reload', $this->enTitle($post));
    }

    public function test_author_without_publish_sees_no_published_option_and_a_forged_save_is_refused(): void
    {
        $author = $this->userWithRole('author');
        $this->actingAs($author);

        Livewire::test(CreatePost::class)
            ->assertFormFieldExists('status', fn ($field) => ! array_key_exists('published', $field->getOptions()));

        $draft = $this->contents()->create(['type' => 'post', 'locale' => 'en', 'title' => 'Mine'], ContentWriteContext::interactive());
        $published = $this->makeContent(['status' => 'published', 'title' => 'Live'], $author);

        // The published record's status is locked for the author…
        Livewire::withQueryParams(['locale' => 'en'])->test(EditPost::class, ['record' => $published->id])
            ->assertFormFieldIsDisabled('status')
            // …and any public change is refused at the service boundary anyway.
            ->fillForm(['title' => 'Author rewrite'])->call('save')->assertNotified(tn_trans('Not saved'));

        $this->assertSame('Live', $this->enTitle($published));
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_an_omitted_body_field_preserves_the_body_and_an_explicit_empty_value_clears_it(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $post = $this->makeContent(['title' => 'Body', 'content' => '<p>Keep me</p>'], $admin);
        $page = Livewire::withQueryParams(['locale' => 'en'])->test(EditPost::class, ['record' => $post->id])->instance();
        $handle = new ReflectionMethod($page, 'handleRecordUpdate');

        // A schema hook removed the body field: it is absent from the form state.
        $handle->invoke($page, $post->fresh(), ['title' => 'Body', 'status' => 'draft']);
        $this->assertSame('<p>Keep me</p>', $post->translations()->where('locale', 'en')->value('content'));

        // The field is present but cleared: the body is cleared (CORE-EDITOR-1B).
        $handle->invoke($page, $post->fresh(), ['title' => 'Body', 'status' => 'draft', 'content' => null]);
        $this->assertSame('', (string) $post->translations()->where('locale', 'en')->value('content'));
    }

    public function test_bulk_status_reports_denied_and_conflicting_records_without_touching_them(): void
    {
        $author = $this->userWithRole('author');
        $other = $this->userWithRole('author');
        $own = $this->makeContent(['title' => 'Own draft'], $author);
        $stale = $this->makeContent(['title' => 'Own but stale'], $author);
        $foreign = $this->makeContent(['title' => 'Foreign draft'], $other);
        $live = $this->makeContent(['title' => 'Own published', 'status' => 'published'], $author);
        $before = [$foreign->id => $this->contentState($foreign->id), $live->id => $this->contentState($live->id)];

        $this->actingAs($author);
        $revisions = json_encode([
            (string) $own->id => $own->revision,
            (string) $stale->id => $stale->revision - 1, // captured before someone else saved
            (string) $foreign->id => $foreign->revision,
            (string) $live->id => $live->revision,
        ]);

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$own->id, $stale->id, $foreign->id, $live->id], ['status' => 'pending', 'revisions' => $revisions])
            ->assertNotified();

        $this->assertSame('pending', $own->fresh()->status);
        $this->assertSame('draft', $stale->fresh()->status, 'stale selection is a conflict');
        $this->assertSame($before[$foreign->id], $this->contentState($foreign->id));
        $this->assertSame($before[$live->id], $this->contentState($live->id));
    }

    public function test_managed_content_redirects_to_its_extension_editor_or_is_refused(): void
    {
        $this->useAuthorityFixturePlugins();
        $plugin = $this->guardedPlugin('ca-admin', 'own-only');
        $this->actingAs($admin = $this->superAdmin());
        $managed = $plugin->createManaged(['type' => 'post', 'locale' => 'en', 'title' => 'Structured', 'status' => 'draft'], $admin);
        $editUrl = '/admin/posts/'.$managed->id.'/edit';

        // No extension editor URL: the native editor refuses to open it.
        $this->get($editUrl)->assertForbidden();

        add_filter('cms.content.edit_url', fn ($url, Content $content) => $content->managed_by === 'ca-admin' ? '/admin/structured/'.$content->id : $url, 10, 2);
        $this->get($editUrl)->assertRedirect('/admin/structured/'.$managed->id);
        $this->assertSame('/admin/structured/'.$managed->id, \App\Filament\Admin\Resources\PostResource::contentRecordUrl($managed));

        // Only same-site URLs are accepted.
        $this->assertNull(ManagedContentEditUrl::sameSite('//evil.example/x'));
        $this->assertNull(ManagedContentEditUrl::sameSite('https://evil.example/x'));
        $this->assertNull(ManagedContentEditUrl::sameSite('javascript:alert(1)'));
        $this->assertNull(ManagedContentEditUrl::sameSite('/admin\\x'));
        $this->assertSame('/admin/structured/1', ManagedContentEditUrl::sameSite('/admin/structured/1'));
    }

    public function test_the_native_page_editor_cannot_save_managed_content_even_when_reached(): void
    {
        $this->useAuthorityFixturePlugins();
        $plugin = $this->guardedPlugin('ca-admin2', 'own-only');
        $this->actingAs($admin = $this->superAdmin());
        $managed = $plugin->createManaged(['type' => 'page', 'locale' => 'en', 'title' => 'Structured page', 'status' => 'draft'], $admin);
        $state = $this->contentState($managed->id);

        // Simulate a forged Livewire save that bypassed the mount redirect.
        $page = new EditPage;
        $page->record = $managed;
        $page->contentRevision = $managed->revision;

        try {
            (new ReflectionMethod($page, 'handleRecordUpdate'))->invoke($page, $managed, ['title' => 'Native overwrite', 'content' => '<p>x</p>']);
        } catch (\Filament\Support\Exceptions\Halt) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($state, $this->contentState($managed->id));
    }

    public function test_plugins_receive_the_record_in_the_form_schema_context(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $post = $this->makeContent(['title' => 'Ctx'], $admin);
        $seen = [];

        add_filter('cms.form.schema.post', function ($components, ...$args) use (&$seen) {
            $seen[] = $args;

            return $components;
        }, 10, 5);

        Livewire::withQueryParams(['locale' => 'en'])->test(EditPost::class, ['record' => $post->id]);

        $records = array_filter(array_map(static function (array $args) {
            foreach ($args as $arg) {
                if (is_array($arg) && ($arg['record'] ?? null) instanceof Content) {
                    return $arg['record'];
                }
                if (is_object($arg) && method_exists($arg, 'get') && $arg->get('record') instanceof Content) {
                    return $arg->get('record');
                }
            }

            return null;
        }, $seen));

        $this->assertNotEmpty($records, 'the form schema hook received no record');
        $this->assertSame($post->id, reset($records)->id);
    }
}
