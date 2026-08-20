<x-filament-panels::page>
    {{ $this->table }}

    @php($invalidPlugins = $this->getInvalidPlugins())

    @if (count($invalidPlugins) > 0)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">{{ tn_trans('Invalid Plugins') }}</x-slot>
            <x-slot name="description">
                {{ tn_trans('These folders were skipped (never loaded) because their manifest is missing or invalid.') }}
            </x-slot>

            {{-- Inline styles guarantee a tabular, bordered look independent of the
                 panel's precompiled utility set. --}}
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                    <tr style="text-align:left;">
                        <th style="padding:0.5rem 0;font-weight:600;width:35%;">{{ tn_trans('Plugin / folder') }}</th>
                        <th style="padding:0.5rem 0;font-weight:600;">{{ tn_trans('Reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invalidPlugins as $invalid)
                        <tr style="border-top:1px solid rgba(125,125,125,0.2);">
                            <td style="padding:0.625rem 0;font-weight:500;">{{ $invalid['slug'] }}</td>
                            <td style="padding:0.625rem 0;">{{ $invalid['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
