@php
    // M6 (CORE-40-H-Z1-M5-M6-CLOSURE-R1, WCAG 2.1.1). Below their min-width the two data tables scroll
    // horizontally inside their container, which holds nothing focusable. While (and only while) the
    // container really overflows it becomes one Tab stop: tabindex=0, role=region, named by the table
    // caption, so the browser's own arrow-key scrolling reaches the hidden columns. When the table fits
    // (desktop) no attribute is added and the Tab order is unchanged. The script only measures; it never
    // scrolls. wire:ignore.self keeps Livewire re-renders from dropping the bound attributes.
    $scrollRegion = '{ scrolls: false, ro: null, init() { const m = () => { this.scrolls = this.$el.scrollWidth > this.$el.clientWidth; }; m(); this.ro = new ResizeObserver(m); this.ro.observe(this.$el); }, destroy() { this.ro?.disconnect(); } }';
    $o = $this->overview();
    $rows = $this->providerRows();
    $diag = $this->lastDiagnostics();
    $fmt = static fn (?int $t): ?string => $t === null ? null : \Illuminate\Support\Carbon::createFromTimestamp($t)->timezone(config('app.timezone'))->format('Y-m-d H:i');
    $state = static fn (string $s): string => match ($s) {
        'pass' => tn_trans('Pass'),
        'warn' => tn_trans('Warning'),
        'fail' => tn_trans('Failed'),
        'skip' => tn_trans('Skipped'),
        'skipped' => tn_trans('Skipped'),
        'busy' => tn_trans('Busy'),
        default => $s,
    };
    $tone = static fn (string $s): string => match ($s) {
        'pass' => 'text-success-700 dark:text-success-400',
        'fail' => 'text-danger-700 dark:text-danger-400',
        'warn', 'busy' => 'text-warning-700 dark:text-warning-400',
        default => 'text-gray-600 dark:text-gray-400',
    };
    $icon = static fn (string $s): string => match ($s) {
        'pass' => '✓',
        'fail' => '✕',
        'warn', 'busy' => '!',
        default => '–',
    };
    $checkLabel = static fn (string $k): string => match ($k) {
        'indexing_policy' => tn_trans('Site-wide indexing policy'),
        'cache' => tn_trans('Cache health'),
        'reserved_routes' => tn_trans('Reserved route conflicts'),
        'robots' => tn_trans('robots.txt'),
        'index' => tn_trans('Sitemap index XML'),
        default => $k,
    };
@endphp

<x-filament-panels::page>
    <section aria-labelledby="sm-overview-heading" class="tn-sitemap-overview">
        <x-filament::section>
            <x-slot name="heading"><span id="sm-overview-heading">{{ tn_trans('Overview') }}</span></x-slot>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('Sitemap status') }}</dt>
                    {{-- role="status" sits inside the <dd> so the list keeps its definition semantics (H-Z1, WCAG 1.3.1). --}}
                    <dd class="text-lg font-semibold {{ $o['enabled'] ? $tone('pass') : $tone('warn') }}">
                        <span role="status">
                            <span aria-hidden="true">{{ $o['enabled'] ? '✓' : '!' }}</span>
                            {{ $o['enabled'] ? tn_trans('Served') : tn_trans('Not served') }}
                        </span>
                    </dd>
                    @unless ($o['enabled'])
                        <dd class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $o['site_indexable'] ? tn_trans('Switched off on this page.') : tn_trans('The site discourages indexing.') }}
                        </dd>
                    @endunless
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('Public files') }}</dt>
                    <dd class="text-sm">
                        <a href="{{ $o['index_url'] }}" target="_blank" rel="noopener" class="tn-admin-link">/sitemap.xml</a>
                        ·
                        <a href="{{ $o['robots_url'] }}" target="_blank" rel="noopener" class="tn-admin-link">/robots.txt</a>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('Plugin providers') }}</dt>
                    <dd class="text-sm">
                        {{ tn_trans(':registered registered · :active included · :excluded excluded', ['registered' => $o['registered'], 'active' => $o['active'], 'excluded' => $o['excluded']]) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('Child sitemap files') }}</dt>
                    <dd class="text-lg font-semibold">{{ $o['children'] ?? tn_trans('Not built yet') }}</dd>
                    <dd class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $o['children_complete'] ? tn_trans('From cached manifests.') : tn_trans('From cached manifests; some providers have not been built yet.') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('URL capacity (estimate)') }}</dt>
                    <dd class="text-lg font-semibold">{{ $o['url_capacity'] === null ? '—' : tn_trans('up to :n', ['n' => number_format($o['url_capacity'])]) }}</dd>
                    <dd class="text-xs text-gray-500 dark:text-gray-400">{{ tn_trans('Child files × URLs per file. Exact URL counts are not computed here.') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('Last successful generation') }}</dt>
                    <dd class="text-sm">{{ $fmt($o['last_built']) ?? tn_trans('Never') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('Last cache refresh') }}</dt>
                    <dd class="text-sm">{{ $fmt($o['last_refresh']) ?? tn_trans('Never') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ tn_trans('Health') }}</dt>
                    <dd class="text-sm {{ $o['health'] ? $tone($o['health']) : '' }}">
                        @if ($o['health'])
                            <span aria-hidden="true">{{ $icon($o['health']) }}</span> {{ $state($o['health']) }} · {{ $fmt($o['health_at']) }}
                        @else
                            {{ tn_trans('Diagnostics have not been run.') }}
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($o['failures'] !== [])
                <div class="mt-4 rounded-lg border border-danger-300 p-3 text-sm dark:border-danger-700" role="alert">
                    <strong>{{ tn_trans('Recent provider failures') }}:</strong>
                    @foreach ($o['failures'] as $f)
                        <span>{{ $f['id'] }} ({{ $f['error'] ?? tn_trans('error') }}, {{ $fmt($f['failed_at']) }})</span>@unless ($loop->last), @endunless
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </section>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($this->canManage())
            <div class="flex items-center justify-end gap-3">
                <x-filament::button type="submit" wire:loading.attr="disabled">
                    {{ tn_trans('Save') }}
                </x-filament::button>
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400" role="note">{{ tn_trans('You can view these settings but not change them.') }}</p>
        @endif
    </form>

    <section aria-labelledby="sm-providers-heading">
        <x-filament::section>
            <x-slot name="heading"><span id="sm-providers-heading">{{ tn_trans('Provider status') }}</span></x-slot>
            {{-- M6 (WCAG 2.1.1): see tnScrollRegion below. --}}
            <div class="overflow-x-auto tn-scroll-region" wire:ignore.self x-data="{{ $scrollRegion }}" x-bind:tabindex="scrolls ? 0 : null" x-bind:role="scrolls ? 'region' : null" x-bind:aria-labelledby="scrolls ? 'sm-providers-caption' : null">
                <table class="w-full text-left text-sm" style="min-width: 44rem">
                    <caption id="sm-providers-caption" class="sr-only">{{ tn_trans('Provider status') }}</caption>
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th scope="col" class="py-2 pe-4">{{ tn_trans('Provider') }}</th>
                            <th scope="col" class="py-2 pe-4">{{ tn_trans('Plugin') }}</th>
                            <th scope="col" class="py-2 pe-4">{{ tn_trans('In sitemap') }}</th>
                            <th scope="col" class="py-2 pe-4">{{ tn_trans('Child files') }}</th>
                            <th scope="col" class="py-2 pe-4">{{ tn_trans('Last successful generation') }}</th>
                            <th scope="col" class="py-2">{{ tn_trans('Last error') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                <th scope="row" class="py-2 pe-4 font-medium">
                                    {{ $row['name'] }}
                                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['id'] }}</span>
                                </th>
                                <td class="py-2 pe-4">
                                    @if ($row['plugin'] === null)
                                        {{ tn_trans('Core') }}
                                    @else
                                        {{ $row['registered'] ? tn_trans('Registered') : tn_trans('Not registered') }} ·
                                        {{ $row['active'] ? tn_trans('Active') : tn_trans('Inactive') }}
                                    @endif
                                </td>
                                <td class="py-2 pe-4 {{ $row['included'] ? $tone('pass') : $tone('skip') }}">
                                    <span aria-hidden="true">{{ $row['included'] ? '✓' : '–' }}</span>
                                    {{ $row['included'] ? tn_trans('Included') : tn_trans('Excluded') }}
                                </td>
                                <td class="py-2 pe-4">{{ $row['children'] ?? '—' }}</td>
                                <td class="py-2 pe-4">{{ $fmt($row['built_at']) ?? '—' }}</td>
                                @php($current = ($row['failed_at'] ?? 0) > ($row['built_at'] ?? 0))
                                <td class="py-2 {{ $current ? $tone('fail') : '' }}">
                                    @if ($row['failed_at'])
                                        {{ ($row['error'] ?? tn_trans('error')).' · '.$fmt($row['failed_at']) }}
                                        @unless ($current)
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ tn_trans('Resolved by a later successful generation.') }}</span>
                                        @endunless
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </section>

    <section aria-labelledby="sm-diag-heading">
        <x-filament::section>
            <x-slot name="heading"><span id="sm-diag-heading">{{ tn_trans('Diagnostics') }}</span></x-slot>
            @if ($diag === null)
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ tn_trans('Diagnostics have not been run.') }}</p>
            @else
                <p class="mb-3 text-sm {{ $tone($diag['status']) }}" role="status">
                    <span aria-hidden="true">{{ $icon($diag['status']) }}</span>
                    {{ $state($diag['status']) }} · {{ $fmt($diag['ran_at']) }} · {{ tn_trans(':ms ms', ['ms' => $diag['duration_ms']]) }}
                    @if ($diag['partial']) · {{ tn_trans('Partial: the time budget was reached.') }} @endif
                </p>
                <div class="overflow-x-auto tn-scroll-region" wire:ignore.self x-data="{{ $scrollRegion }}" x-bind:tabindex="scrolls ? 0 : null" x-bind:role="scrolls ? 'region' : null" x-bind:aria-labelledby="scrolls ? 'sm-diagnostics-caption' : null">
                    <table class="w-full text-left text-sm" style="min-width: 32rem">
                        <caption id="sm-diagnostics-caption" class="sr-only">{{ tn_trans('Diagnostic checks') }}</caption>
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th scope="col" class="py-2 pe-4">{{ tn_trans('Check') }}</th>
                                <th scope="col" class="py-2 pe-4">{{ tn_trans('Result') }}</th>
                                <th scope="col" class="py-2">{{ tn_trans('Detail') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($diag['checks'] as $c)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <th scope="row" class="py-2 pe-4 font-medium">{{ $checkLabel($c['key']) }}</th>
                                    <td class="py-2 pe-4 {{ $tone($c['status']) }}"><span aria-hidden="true">{{ $icon($c['status']) }}</span> {{ $state($c['status']) }}</td>
                                    <td class="py-2">{{ tn_trans($c['detail'], $c['params'] ?? []) }}</td>
                                </tr>
                            @endforeach
                            @foreach ($diag['providers'] as $p)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <th scope="row" class="py-2 pe-4 font-medium">{{ tn_trans('Provider') }} <span class="font-mono">{{ $p['id'] }}</span></th>
                                    <td class="py-2 pe-4 {{ $tone($p['status']) }}"><span aria-hidden="true">{{ $icon($p['status']) }}</span> {{ $state($p['status']) }}</td>
                                    <td class="py-2">
                                        {{ $p['detail'] !== '' ? tn_trans($p['detail'], $p['params'] ?? []) : '' }}
                                        @isset($p['children'])
                                            {{ tn_trans(':children child files, :urls URLs in the sampled file', ['children' => $p['children'], 'urls' => $p['sampled_urls'] ?? 0]) }}
                                        @endisset
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($diag['duplicates'] !== [])
                    <p class="mt-3 text-sm {{ $tone('warn') }}" role="alert">
                        {{ tn_trans('Duplicate URLs across providers (sampled):') }} {{ implode(', ', $diag['duplicates']) }}
                    </p>
                @endif
            @endif
        </x-filament::section>
    </section>
</x-filament-panels::page>
