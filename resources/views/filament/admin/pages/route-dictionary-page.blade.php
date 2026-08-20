<x-filament-panels::page>
    {{-- Read-only overview of the effective dictionary with ownership. Inherited (Core/Plugin)
         entries are read-only; project overrides are edited in the form below. --}}
    <section class="space-y-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ tn_trans('Search key or segment…') }}"
                class="fi-input block w-full rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
            />

            <select
                wire:model.live="localeFilter"
                class="fi-select block w-full rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
            >
                <option value="">{{ tn_trans('All locales') }}</option>
                @foreach ($this->localeOptions() as $locale)
                    <option value="{{ $locale }}">{{ $locale }}</option>
                @endforeach
            </select>

            <select
                wire:model.live="ownerFilter"
                class="fi-select block w-full rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
            >
                <option value="">{{ tn_trans('All owners') }}</option>
                <option value="core">{{ tn_trans('Core') }}</option>
                <option value="plugin">{{ tn_trans('Plugin') }}</option>
                <option value="project">{{ tn_trans('Project') }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ tn_trans('Route Key') }}</th>
                        <th class="px-3 py-2 font-medium">{{ tn_trans('Locale') }}</th>
                        <th class="px-3 py-2 font-medium">{{ tn_trans('Localized segment') }}</th>
                        <th class="px-3 py-2 font-medium">{{ tn_trans('Owner') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @forelse ($this->filteredOverview() as $row)
                        @php
                            $owner = $row['owner']->value;
                            $tone = ['core' => 'gray', 'plugin' => 'warning', 'project' => 'success'][$owner] ?? 'gray';
                        @endphp
                        <tr>
                            <td class="px-3 py-2 font-mono">{{ $row['key'] }}</td>
                            <td class="px-3 py-2">{{ $row['locale'] }}</td>
                            <td class="px-3 py-2 font-mono">{{ $row['segment'] }}</td>
                            <td class="px-3 py-2">
                                <x-filament::badge :color="$tone">{{ $row['owner']->label() }}</x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6 text-center text-gray-500">
                                {{ tn_trans('No entries match the current filters.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Project overrides editor. Saving validates through the Runtime authorities and rebuilds
         the immutable Dictionary (live on the next boot). --}}
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                {{ tn_trans('Save Route Dictionary') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
