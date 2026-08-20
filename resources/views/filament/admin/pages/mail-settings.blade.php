<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                {{ tn_trans('Save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
