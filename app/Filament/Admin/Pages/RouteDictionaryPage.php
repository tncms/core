<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use TheNguyen\CMS\Localization\Dictionary\Administration\EntryOwnership;
use TheNguyen\CMS\Localization\Dictionary\Administration\Exceptions\RouteDictionaryAdministrationException;
use TheNguyen\CMS\Localization\Dictionary\Administration\RouteDictionaryManager;

/**
 * CMS → Route Dictionary (P6.4).
 *
 * The first administration layer for the persisted PROJECT Dictionary. Administrators manage
 * project-owned localized route segments; Core (frozen seed) and Plugin entries are inherited and
 * READ-ONLY. Every edit is validated by {@see RouteDictionaryManager} through the frozen Runtime
 * authorities and, on save, rebuilds the immutable Runtime Dictionary (live on the next boot).
 *
 * This page is a consumer only — it never touches the Runtime, Composer, Loader, or plugin sources.
 */
class RouteDictionaryPage extends Page
{
    protected static ?string $slug = 'localization/route-dictionary';

    protected static string|\UnitEnum|null $navigationGroup = 'CMS';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 22;

    protected string $view = 'filament.admin.pages.route-dictionary-page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Overview filter state (read-only inherited + project entries). */
    public string $search = '';

    public ?string $localeFilter = null;

    public ?string $ownerFilter = null;

    public static function canAccess(): bool
    {
        return cms_can('languages.manage');
    }

    public static function getNavigationLabel(): string
    {
        return tn_trans('Route Dictionary');
    }

    public function getTitle(): string
    {
        return tn_trans('Route Dictionary');
    }

    private function manager(): RouteDictionaryManager
    {
        return app(RouteDictionaryManager::class);
    }

    public function mount(): void
    {
        $this->data = ['overrides' => $this->overrideRows()];
        $this->form->fill($this->data);
    }

    /** Flatten the persisted project overrides into repeater rows. */
    private function overrideRows(): array
    {
        $rows = [];

        foreach ($this->manager()->projectOverrides() as $key => $localeMap) {
            foreach ($localeMap as $locale => $segment) {
                $rows[] = ['key' => (string) $key, 'locale' => (string) $locale, 'segment' => (string) $segment];
            }
        }

        return $rows;
    }

    /** Distinct locales across the effective dictionary — the locale options + filter. */
    public function localeOptions(): array
    {
        $locales = [];

        foreach ($this->manager()->overview() as $row) {
            $locales[$row['locale']] = $row['locale'];
        }

        ksort($locales);

        return $locales;
    }

    /**
     * The effective dictionary rows for the read-only overview, honouring the search / locale /
     * ownership filters.
     *
     * @return list<array{key: string, locale: string, segment: string, owner: EntryOwnership}>
     */
    public function filteredOverview(): array
    {
        $search = trim(mb_strtolower($this->search));

        return array_values(array_filter($this->manager()->overview(), function (array $row) use ($search): bool {
            if ($this->localeFilter && $row['locale'] !== $this->localeFilter) {
                return false;
            }

            if ($this->ownerFilter && $row['owner']->value !== $this->ownerFilter) {
                return false;
            }

            if ($search !== '' && ! str_contains(mb_strtolower($row['key'].' '.$row['segment']), $search)) {
                return false;
            }

            return true;
        }));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('overrides')
                    ->label(tn_trans('Project overrides'))
                    ->helperText(tn_trans('Project-owned localized segments. To override a Core (inherited) segment, add its Route Key + locale with your segment. Plugin-owned segments cannot be overridden.'))
                    ->schema([
                        TextInput::make('key')
                            ->label(tn_trans('Route Key'))
                            ->required()
                            ->maxLength(64)
                            ->placeholder('faq'),
                        Select::make('locale')
                            ->label(tn_trans('Locale'))
                            ->required()
                            ->options(fn (): array => $this->localeOptions())
                            ->native(false)
                            ->searchable()
                            ->allowHtml(false),
                        TextInput::make('segment')
                            ->label(tn_trans('Localized segment'))
                            ->required()
                            ->maxLength(191)
                            ->placeholder('cau-hoi-thuong-gap'),
                    ])
                    ->columns(3)
                    ->addActionLabel(tn_trans('Add project override'))
                    ->default([]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $overrides = [];
        foreach (is_array($state['overrides'] ?? null) ? $state['overrides'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            $locale = trim((string) ($row['locale'] ?? ''));
            $segment = trim((string) ($row['segment'] ?? ''));

            if ($key === '' || $locale === '' || $segment === '') {
                continue;
            }

            $overrides[$key][$locale] = $segment;
        }

        try {
            $this->manager()->save($overrides, auth()->id());
        } catch (RouteDictionaryAdministrationException $e) {
            Notification::make()
                ->title(tn_trans('Route Dictionary not saved'))
                ->body(implode("\n", array_slice($e->errors, 0, 8)))
                ->danger()
                ->send();

            return;
        }

        // Reflect the persisted, normalised project overrides back into the form.
        $this->data = ['overrides' => $this->overrideRows()];
        $this->form->fill($this->data);

        Notification::make()->title(tn_trans('Route Dictionary saved'))->success()->send();
    }
}
