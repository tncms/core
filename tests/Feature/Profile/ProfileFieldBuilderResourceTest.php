<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages\CreateProfileField;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages\ListProfileFields;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;

/**
 * P2 Slice B — Filament Field Builder resource (Livewire) checks. The resource
 * pages delegate all writes to ProfileFieldAdminService (proven in
 * ProfileFieldAdminServiceTest); here we verify authorization and that the
 * Livewire create page actually routes through the audited service.
 * Visible-browser certification is NOT performed (see P2 report).
 */
class ProfileFieldBuilderResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_render_the_field_list(): void
    {
        // No roles seeded → Core RBAC fails open → the authenticated user is
        // authorized (matches the existing admin-test baseline).
        $this->actingAs(User::factory()->create());

        Livewire::test(ListProfileFields::class)->assertOk();
    }

    public function test_create_page_routes_through_the_audited_service(): void
    {
        $this->actingAs(User::factory()->create());
        $group = UserProfileFieldGroup::query()->create(['key' => 'misc', 'source' => 'admin']);

        Livewire::test(CreateProfileField::class)
            ->fillForm([
                'key' => 'custom.linkedin',
                'group_id' => $group->id,
                'type' => 'url',
                'visibility' => 'public',
                'editability' => 'owner',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $field = UserProfileField::query()->where('key', 'custom.linkedin')->first();
        $this->assertNotNull($field);
        $this->assertSame('admin', $field->source); // written by the service, not a raw save
        $this->assertFalse((bool) $field->is_locked);
    }

    public function test_create_page_rejects_a_non_custom_namespace(): void
    {
        $this->actingAs(User::factory()->create());
        $group = UserProfileFieldGroup::query()->create(['key' => 'misc', 'source' => 'admin']);

        // The service enforces the reserved admin namespace even through the UI.
        $this->expectException(\TheNguyen\CMS\Exceptions\ReservedProfileFieldKeyException::class);
        Livewire::test(CreateProfileField::class)
            ->fillForm(['key' => 'acme.thing', 'group_id' => $group->id, 'type' => 'text'])
            ->call('create');
    }

    public function test_non_admin_cannot_access_the_field_list(): void
    {
        // Seeding roles initializes RBAC (fail-open OFF); a roleless user then
        // lacks profile_fields.manage and must be forbidden.
        $this->seed(CmsRolePermissionSeeder::class);
        $this->assertGreaterThan(0, Role::query()->count());
        $this->actingAs(User::factory()->create());

        Livewire::test(ListProfileFields::class)->assertForbidden();
    }
}
