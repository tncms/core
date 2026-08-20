<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Filament\Admin\Pages\RouteDictionaryPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use TheNguyen\CMS\Localization\Dictionary\Administration\RouteDictionaryManager;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Persistence\ArrayFileRouteDictionaryStore;
use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface;
use Tests\TestCase;

/**
 * P6.4 — the Route Dictionary Filament page (access control + save flow). The Runtime store is
 * pointed at a TEMP file so the shipped config/cms-route-dictionary.php is never written.
 */
final class RouteDictionaryPageTest extends TestCase
{
    use RefreshDatabase;

    private string $storePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storePath = sys_get_temp_dir().'/tncms-route-dict-page-'.uniqid().'.php';

        config(['cms.route_dictionary_path' => $this->storePath]);
        $this->app->instance(RouteDictionaryStoreInterface::class, new ArrayFileRouteDictionaryStore($this->storePath));
        $this->app->forgetInstance(RouteDictionaryManager::class);
        $this->app->forgetInstance('cms.localization.dictionary');
        $this->app->forgetInstance(RouteDictionaryComposer::class);
    }

    protected function tearDown(): void
    {
        if (is_file($this->storePath)) {
            @unlink($this->storePath);
        }

        parent::tearDown();
    }

    public function test_access_requires_authentication(): void
    {
        $this->assertFalse(RouteDictionaryPage::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertTrue(RouteDictionaryPage::canAccess());
    }

    public function test_save_persists_a_project_override_through_the_manager(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(RouteDictionaryPage::class)
            ->set('data.overrides', [['key' => 'products', 'locale' => 'vi', 'segment' => 'san-pham-moi']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['products' => ['vi' => 'san-pham-moi']],
            app(RouteDictionaryManager::class)->projectOverrides(),
        );
    }

    public function test_save_rejects_invalid_input_and_persists_nothing(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(RouteDictionaryPage::class)
            ->set('data.overrides', [['key' => 'event', 'locale' => 'vi', 'segment' => 'Su Kien']])
            ->call('save');

        $this->assertSame([], app(RouteDictionaryManager::class)->projectOverrides());
        $this->assertFalse(is_file($this->storePath), 'Invalid input must persist nothing.');
    }
}
