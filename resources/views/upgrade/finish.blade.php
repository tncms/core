@extends('upgrade.layout')

@section('title', 'Finish')

@section('content')
    @php($status = $state['status'] ?? null)

    @if ($status === \TheNguyen\CMS\Upgrade\UpgradeState::COMPLETED)
        <h1>Upgrade complete</h1>
        <div class="alert alert--ok">
            {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} was upgraded successfully to
            <strong>v{{ $state['target_version'] ?? $current }}</strong>.
        </div>
        <div class="kv">
            <span class="label">Now running</span>
            <span class="v">v{{ $state['health']['version'] ?? $state['target_version'] ?? $current }}</span>
        </div>
        <div class="actions">
            <a class="btn btn--ghost" href="{{ url('/') }}">View site</a>
            <a class="btn btn--primary" href="{{ url(config('cms.admin_path', 'admin')) }}">Go to admin →</a>
        </div>
    @elseif ($status === \TheNguyen\CMS\Upgrade\UpgradeState::FAILED)
        <h1>Upgrade did not complete</h1>
        @php($recovered = $state['recovered'] ?? null)
        <div class="alert {{ $recovered ? 'alert--warn' : 'alert--error' }}">
            The upgrade failed at stage <strong>{{ str_replace('_', ' ', $state['failure_stage'] ?? 'unknown') }}</strong>.
            @if ($recovered === true)
                Your site was automatically restored from the verified backup and is running on the previous version.
            @elseif ($recovered === false)
                Automatic recovery could not fully complete — <strong>manual recovery may be required</strong>. Do not run the upgrade again until the site is verified.
            @endif
        </div>
        @if (! empty($state['failure_reason']))
            <div class="kv"><span class="label">Reason</span><span class="v">{{ $state['failure_reason'] }}</span></div>
        @endif
        @if (! empty($state['recovery_notes']))
            <div class="kv"><span class="label">Recovery</span><span class="v">{{ implode('; ', (array) $state['recovery_notes']) }}</span></div>
        @endif
        <div class="actions">
            <a class="btn btn--ghost" href="{{ url(config('cms.admin_path', 'admin')) }}">Back to admin</a>
            <a class="btn btn--primary" href="{{ route('cms.upgrade.index') }}">Upgrade again →</a>
        </div>
    @else
        <h1>No completed upgrade</h1>
        <p class="lead">There is no finished upgrade to show.</p>
        <div class="actions">
            <span></span>
            <a class="btn btn--primary" href="{{ route('cms.upgrade.index') }}">Start an upgrade →</a>
        </div>
    @endif
@endsection
