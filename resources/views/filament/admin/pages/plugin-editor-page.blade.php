<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col items-center gap-3 py-8 text-center">
            <x-filament::icon icon="heroicon-o-code-bracket" class="h-10 w-10 text-gray-400" />
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ tn_trans('Coming in a future beta.') }}
            </h2>
            <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">
                {{ tn_trans('Plugin file editing is security-sensitive and will be limited to safe file types.') }}
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
