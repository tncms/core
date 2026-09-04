<x-filament-panels::page>
    @php($packages = $this->packages())

    <x-filament::section>
        <div class="text-sm text-gray-500 dark:text-gray-400">
            {{ tn_trans('Demos are discovered from installed themes and active plugins. Importing applies theme settings, media, and the homepage layout. Content, menus, widgets, and SEO files are still deferred unless a package handler supports them. Each import is idempotent and can be reset.') }}
        </div>
    </x-filament::section>

    @forelse ($packages as $package)
        <x-filament::section>
            <x-slot name="heading">
                {{ $package['name'] }}
                @if ($package['imported'])
                    <x-filament::badge color="success" class="ms-2">{{ tn_trans('Imported') }}</x-filament::badge>
                @else
                    <x-filament::badge color="gray" class="ms-2">{{ tn_trans('Not imported') }}</x-filament::badge>
                @endif
            </x-slot>

            <x-slot name="description">
                {{ $package['description'] }}
            </x-slot>

            <div class="space-y-4 text-sm">
                @if ($package['preview'])
                    <img
                        src="{{ $package['preview'] }}"
                        alt="{{ $package['name'] }}"
                        class="rounded-lg border border-gray-200 dark:border-white/10 max-w-md"
                    >
                @endif

                <div class="flex flex-wrap gap-x-6 gap-y-1 text-gray-600 dark:text-gray-400">
                    <span><strong>{{ tn_trans('Type') }}:</strong> {{ $package['type'] }}</span>
                    <span><strong>{{ tn_trans('Source') }}:</strong> {{ $package['owner'] }}</span>
                    @if ($package['preset'])
                        <span><strong>{{ tn_trans('Preset') }}:</strong> {{ $package['preset'] }}</span>
                    @endif
                    <span><strong>{{ tn_trans('Version') }}:</strong> {{ $package['version'] ?: '—' }}</span>
                    @if ($package['imported'] && $package['imported_at'])
                        <span><strong>{{ tn_trans('Imported at') }}:</strong> {{ $package['imported_at'] }}</span>
                    @endif
                    @if ($package['imported'] && $package['batch_id'])
                        <span><strong>{{ tn_trans('Batch') }}:</strong> <code>{{ $package['batch_id'] }}</code></span>
                    @endif
                </div>

                @if ($package['warnings'] !== [])
                    <div class="rounded-md bg-warning-50 dark:bg-warning-500/10 p-3 text-warning-700 dark:text-warning-400">
                        <p class="font-medium">{{ tn_trans('Before importing') }}:</p>
                        <ul class="list-disc ps-5 mt-1 space-y-0.5">
                            @foreach ($package['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @php($confirmImport = trim(($package['warnings'] !== [] ? implode(' ', $package['warnings']) . ' ' : '') . tn_trans('Continue with the import?')))
                @php($confirmReset = tn_trans('Reset removes only the pages and menus this demo created and restores the pre-import theme settings and homepage layout. Your own content is not affected. Continue?'))

                <div class="flex flex-wrap items-center gap-3">
                    @if ($package['inactive_theme'] ?? false)
                        <x-filament::badge color="warning">
                            {{ tn_trans('Theme not active — import disabled; reset remains available.') }}
                        </x-filament::badge>
                    @else
                        <x-filament::button
                            color="gray"
                            wire:click="previewPackage('{{ $package['id'] }}')"
                            wire:loading.attr="disabled"
                            icon="heroicon-m-eye"
                        >
                            {{ tn_trans('Preview') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:click="importPackage('{{ $package['id'] }}')"
                            wire:confirm="{{ $confirmImport }}"
                            wire:loading.attr="disabled"
                            icon="heroicon-m-arrow-down-tray"
                        >
                            {{ $package['imported'] ? tn_trans('Re-import') : tn_trans('Import') }}
                        </x-filament::button>
                    @endif

                    @if ($package['imported'] && $package['rollback'])
                        <x-filament::button
                            color="danger"
                            wire:click="resetPackage('{{ $package['id'] }}')"
                            wire:confirm="{{ $confirmReset }}"
                            wire:loading.attr="disabled"
                            icon="heroicon-m-arrow-uturn-left"
                        >
                            {{ tn_trans('Reset') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ tn_trans('No demo packages found. Install a theme or activate a plugin that ships a demo.') }}
            </div>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
