<x-filament-panels::page>
    @if (! $this->hasActiveTheme())
        <x-filament::section>
            <div class="text-sm text-warning-600 dark:text-warning-400">
                {!! tn_trans('No active theme found. Activate a theme under :location to configure its options.', ['location' => '<strong>' . e(tn_trans('Appearance → Themes')) . '</strong>']) !!}
            </div>
        </x-filament::section>
    @else
        @if (! $this->hasOptions())
            {{-- The active theme declares no option schema, but Custom CSS (below) is always available. --}}
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ tn_trans('The active theme:suffix does not define any options.', ['suffix' => $this->activeThemeName() ? ' (' . $this->activeThemeName() . ')' : '']) }}
                </div>
            </x-filament::section>
        @elseif (\App\Filament\Admin\Pages\ImportDemoPage::canAccess())
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {!! tn_trans('Need starter content? :link to populate the homepage with the demo.', ['link' => '<a class="text-primary-600 dark:text-primary-400 font-medium" href="' . e(\App\Filament\Admin\Pages\ImportDemoPage::getUrl()) . '">' . e(tn_trans('Import the Company demo')) . '</a>']) !!}
                </div>
            </x-filament::section>
        @endif

        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex items-center justify-end gap-3">
                <x-filament::button type="submit" wire:loading.attr="disabled">
                    {{ tn_trans('Save theme options') }}
                </x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
