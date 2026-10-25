<x-filament-panels::page>
    {{-- CORE-EXTENSION-TRUST S1: third-party JavaScript trust warning. Plain
         markup + inline styles so it renders independent of the panel's
         precompiled utility set. --}}
    <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
        <x-slot name="heading">{{ tn_trans('Third-party JavaScript') }}</x-slot>
        <p style="font-size:0.875rem;margin:0;">
            {{ tn_trans('An approved script runs with full access to your public pages and their visitors. Approval covers the exact declaration shown here: if the plugin changes the URL, attributes or required origins, the script stops loading until you approve it again. Deactivating a plugin revokes its approvals.') }}
        </p>
    </x-filament::section>

    {{ $this->table }}

    @php($invalid = $this->invalidDeclarations())

    @if (count($invalid) > 0)
        <x-filament::section icon="heroicon-o-x-circle" icon-color="danger">
            <x-slot name="heading">{{ tn_trans('Rejected declarations') }}</x-slot>
            <x-slot name="description">
                {{ tn_trans('These declarations failed validation and can never load.') }}
            </x-slot>

            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                    <tr style="text-align:left;">
                        <th scope="col" style="padding:0.5rem 0;font-weight:600;width:35%;">{{ tn_trans('Plugin') }}</th>
                        <th scope="col" style="padding:0.5rem 0;font-weight:600;">{{ tn_trans('Reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invalid as $row)
                        <tr style="border-top:1px solid rgba(125,125,125,0.2);">
                            <td style="padding:0.625rem 0;font-weight:500;">{{ $row['owner'] }}{{ $row['key'] !== null ? ' / '.$row['key'] : '' }}</td>
                            <td style="padding:0.625rem 0;">{{ $row['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
