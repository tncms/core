<x-filament-panels::page>
    {{-- CORE-H-Z1-REMEDIATION-R1 H-C2 (WCAG 1.4.3): the inline #6b7280 back link was 4.11:1 on the dark page. --}}
    <style>
        .tn-install-back { font-size: .875rem; color: #4b5563; text-decoration: none; }
        .dark .tn-install-back { color: #9ca3af; }
    </style>
    <form wire:submit="install" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ \App\Filament\Admin\Pages\ThemesPage::getUrl() }}"
                class="tn-install-back"
            >
                &larr; {{ tn_trans('Back to Themes') }}
            </a>

            <x-filament::button type="submit" icon="heroicon-m-arrow-up-tray" wire:loading.attr="disabled">
                {{ tn_trans('Install Theme') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
