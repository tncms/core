<x-filament-panels::page>
    <form wire:submit="install" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ \App\Filament\Admin\Pages\ThemesPage::getUrl() }}"
                style="font-size:.875rem;color:#6b7280;text-decoration:none;"
            >
                &larr; {{ tn_trans('Back to Themes') }}
            </a>

            <x-filament::button type="submit" icon="heroicon-m-arrow-up-tray" wire:loading.attr="disabled">
                {{ tn_trans('Install Theme') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
