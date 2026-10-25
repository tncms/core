<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;

/**
 * CORE-EXTENSION-TRUST S3 — the public profile provider on real frontend
 * responses: rendered HTML, response headers and signed preview.
 *
 * The renderer here is a shortcode that stores only a subject reference and a
 * field list (the shape P5/P6 must use) and escapes the projection with e().
 */
final class PublicProfileRenderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_EMAIL = 'b-private@s3.test';

    private const SECRET_PHONE = '+84-900-555-0101';

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
        $this->seed(CmsRolePermissionSeeder::class);

        $group = UserProfileFieldGroup::query()->create(['key' => 'social', 'source' => 'admin', 'sort_order' => 0, 'is_active' => true, 'is_locked' => false]);
        foreach (['custom.site' => ['url', 'public'], 'custom.phone' => ['text', 'owner'], 'custom.motto' => ['text', 'authenticated']] as $key => [$type, $visibility]) {
            UserProfileField::query()->create([
                'key' => $key, 'group_id' => $group->id, 'type' => $type, 'visibility' => $visibility, 'editability' => 'owner',
                'is_encrypted' => false, 'source' => 'admin', 'sort_order' => 0, 'is_active' => true, 'is_locked' => false, 'schema_revision' => 1,
            ]);
        }

        $this->subject = User::factory()->create([
            'name' => 'User B', 'email' => self::SECRET_EMAIL, 'phone' => self::SECRET_PHONE, 'username' => 'b_login_handle',
            'avatar' => 'https://cdn.s3.test/b.png', 'bio' => 'Public bio of B.',
        ]);
        app('cms.user_profile')->set($this->subject, [
            'core.public_profile' => true,
            'custom.site' => 'https://b.s3.test',
            'custom.phone' => self::SECRET_PHONE,
            'custom.motto' => 'members only motto',
        ], $this->subject);

        add_shortcode('s3_profile', function ($atts = []): string {
            $atts = is_array($atts) ? $atts : [];
            $fields = array_values(array_filter(array_map('trim', explode(',', (string) ($atts['fields'] ?? '')))));
            $p = app('cms.public_profile')->project((string) ($atts['id'] ?? ''), $fields);

            if ($p === null) {
                return '<div class="s3-profile s3-empty"></div>';
            }

            $out = '<div class="s3-profile"><h2>'.e((string) $p->name).'</h2>';
            $out .= $p->avatar !== null ? '<img src="'.e($p->avatar['url']).'" alt="'.e($p->avatar['alt']).'">' : '';
            $out .= '<p>'.e((string) $p->bio).'</p>';
            foreach ($p->fields as $f) {
                $out .= '<span>'.e($f['display']).'</span>';
            }

            return $out.'</div>';
        });
    }

    private function page(string $slug, string $shortcode, string $status = 'published'): Content
    {
        return app('cms.content')->create(['type' => 'page', 'status' => $status, 'locale' => 'en', 'title' => 'Team '.$slug, 'slug' => $slug, 'content' => '<p>Team</p>'.$shortcode]);
    }

    private function shortcodeFor(int|string $id): string
    {
        return '[s3_profile id="'.$id.'" fields="name,avatar,bio,field:custom.site,field:custom.phone,field:custom.motto,email,phone"]';
    }

    private function region(string $html): string
    {
        $this->assertSame(1, preg_match('#<div class="s3-profile[^"]*">.*?</div>#s', $html, $m), 'profile region rendered');

        return $m[0];
    }

    private function roleUser(string $role, string $name): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->roles()->syncWithoutDetaching([Role::query()->where('slug', $role)->firstOrFail()->id]);

        return $u->fresh();
    }

    private function assertNoPrivate(string $html): void
    {
        foreach ([self::SECRET_EMAIL, self::SECRET_PHONE, 'b_login_handle', 'members only motto'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_guest_page_renders_only_the_public_projection(): void
    {
        $this->page('team', $this->shortcodeFor($this->subject->id));

        $res = $this->get('/team')->assertOk();
        $region = $this->region($res->getContent());

        $this->assertStringContainsString('<h2>User B</h2>', $region);
        $this->assertStringContainsString('https://cdn.s3.test/b.png', $region);
        $this->assertStringContainsString('<span>https://b.s3.test</span>', $region);
        $this->assertNoPrivate($res->getContent());
    }

    public function test_editor_admin_and_owner_receive_byte_identical_profile_html(): void
    {
        $this->page('team', $this->shortcodeFor($this->subject->id));
        $guest = $this->region($this->get('/team')->getContent());

        foreach ([
            'editor' => $this->roleUser('editor', 'Editor A'),
            'admin' => $this->roleUser('admin', 'Admin C'),
            'owner' => $this->subject,
        ] as $label => $viewer) {
            $html = $this->actingAs($viewer)->get('/team')->assertOk()->getContent();
            $this->assertSame($guest, $this->region($html), $label);
            $this->assertNoPrivate($html);
            auth()->logout();
            $this->flushSession();
        }
    }

    public function test_a_changed_subject_reference_to_a_non_consenting_user_renders_nothing(): void
    {
        $other = User::factory()->create(['name' => 'Customer D', 'email' => 'd@s3.test']);
        $this->page('team', $this->shortcodeFor($other->id));

        $html = $this->actingAs($this->roleUser('admin', 'Admin C'))->get('/team')->assertOk()->getContent();

        $this->assertSame('<div class="s3-profile s3-empty"></div>', $this->region($html));
        $this->assertStringNotContainsString('Customer D', $html);
        $this->assertStringNotContainsString('d@s3.test', $html);
    }

    public function test_the_profile_never_changes_cache_headers_with_optimization_on_or_off(): void
    {
        $this->page('team', $this->shortcodeFor($this->subject->id));
        $this->page('plain', '');
        $editor = $this->roleUser('editor', 'Editor A');

        foreach ([false, true] as $optimize) {
            app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, $optimize);

            foreach (['guest' => null, 'editor' => $editor] as $label => $viewer) {
                $viewer !== null ? $this->actingAs($viewer) : null;
                $with = $this->get('/team')->assertOk()->headers->get('Cache-Control');
                $without = $this->get('/plain')->assertOk()->headers->get('Cache-Control');

                $this->assertSame($without, $with, "{$label} optimize=".var_export($optimize, true));

                if ($optimize) {
                    $this->assertSame($viewer === null ? 'no-cache, private' : 'no-store, private', $with);
                }

                auth()->logout();
                $this->flushSession();
            }
        }
    }

    public function test_signed_preview_shows_the_same_public_projection_with_preview_headers(): void
    {
        $this->page('team', $this->shortcodeFor($this->subject->id));
        $guestRegion = $this->region($this->get('/team')->getContent());
        $draft = $this->page('team-draft', $this->shortcodeFor($this->subject->id), 'draft');
        $admin = $this->roleUser('admin', 'Admin C');

        $res = $this->actingAs($admin)->get(app('cms.preview')->temporaryUrl($draft))->assertOk();

        $this->assertSame($guestRegion, $this->region($res->getContent()));
        $this->assertNoPrivate($res->getContent());
        $this->assertSame('must-revalidate, no-cache, no-store, private', $res->headers->get('Cache-Control'));
    }

    public function test_sessions_do_not_leak_into_each_other(): void
    {
        $this->page('team', $this->shortcodeFor($this->subject->id));

        $owner = $this->actingAs($this->subject)->get('/team')->getContent();
        auth()->logout();
        $this->flushSession();
        $guest = $this->get('/team')->getContent();

        $this->assertSame($this->region($owner), $this->region($guest));
        $this->assertNoPrivate($guest);
        $this->assertNoPrivate($owner);
    }

    public function test_the_owner_opts_in_and_out_through_the_existing_account_form(): void
    {
        $c = User::factory()->create(['name' => 'User C']);
        $this->assertNull(app('cms.public_profile')->project($c->id, ['name']));

        $form = $this->actingAs($c)->get('/account/profile')->assertOk()->getContent();
        $this->assertStringContainsString('Show my public profile', $form);
        $this->assertStringContainsString('core.public_profile', $form);

        $this->post('/account/profile/fields', ['profile' => ['core.public_profile' => '1'], 'profile_present' => ['core.public_profile']])->assertRedirect();
        $this->assertSame('User C', app('cms.public_profile')->project($c->id, ['name'])?->name);

        $this->post('/account/profile/fields', ['profile' => [], 'profile_present' => ['core.public_profile']])->assertRedirect();
        $this->assertNull(app('cms.public_profile')->project($c->id, ['name']));
    }

    public function test_another_user_cannot_flip_someone_elses_consent(): void
    {
        $c = User::factory()->create(['name' => 'User C']);
        $editor = $this->roleUser('editor', 'Editor A');

        $this->actingAs($editor)->post('/account/profile/fields', [
            'user_id' => $c->id,
            'profile' => ['core.public_profile' => '1'],
            'profile_present' => ['core.public_profile'],
        ]);

        $this->assertNull(app('cms.public_profile')->project($c->id, ['name']));
        $this->assertNotNull(app('cms.public_profile')->project($editor->id, ['name']), 'the write landed on the editor\'s own account only');
    }
}
