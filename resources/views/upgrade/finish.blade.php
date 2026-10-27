@extends('upgrade.layout')

@section('title', 'Finish')

@section('content')
    @php($status = $state['status'] ?? null)
    @php($recovery = $recovery ?? null)

    @if ($recovery !== null)
        <h1>An upgrade needs recovery</h1>
        <div class="alert alert--error">
            @if (($recovery['outcome'] ?? null) === \TheNguyen\CMS\Upgrade\UpgradeOutcome::RECOVERY_REQUIRED)
                A previous upgrade could not be recovered automatically, so the site has not been fully restored yet.
            @else
                A previous upgrade did not finish (stopped at stage
                <strong>{{ str_replace('_', ' ', (string) ($recovery['status'] ?? 'unknown')) }}</strong>) and has not been recovered yet.
            @endif
            New upgrades are blocked until recovery is verified. Close programs that may hold site files open
            (editors, file explorers, terminals, sync or backup tools), make sure some disk space is free, then recover.
            If an upgrade is still running in another window, wait for it to finish first.
        </div>
        <div class="kv"><span class="label">Incident ID</span><span class="v"><code>{{ $recovery['upgrade_id'] ?? '—' }}</code></span></div>
        @if (! empty($recovery['failure_reason']))
            <div class="kv"><span class="label">Reason</span><span class="v">{{ $recovery['failure_reason'] }}</span></div>
        @endif
        @if (! empty($recovery['recovery_notes']))
            <div class="kv"><span class="label">Recovery</span><span class="v">{{ implode('; ', (array) $recovery['recovery_notes']) }}</span></div>
        @endif
        <div class="actions">
            <a class="btn btn--ghost" href="{{ url(config('cms.admin_path', 'admin')) }}">Back to admin</a>
            <form method="POST" action="{{ route('cms.upgrade.recover') }}">
                @csrf
                <button type="submit" class="btn btn--primary">Recover now</button>
            </form>
        </div>
    @elseif ($status === \TheNguyen\CMS\Upgrade\UpgradeState::COMPLETED)
        <h1>Upgrade complete</h1>
        <div class="alert alert--ok">
            {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} was upgraded successfully to
            <strong>v{{ $state['target_version'] ?? $current }}</strong>.
        </div>
        <div class="kv">
            <span class="label">Now running</span>
            <span class="v">v{{ $state['health']['version'] ?? $state['target_version'] ?? $current }}</span>
        </div>
        @if (($state['cleanup']['cleanup_ok'] ?? true) === false)
            <div class="alert alert--warn">
                The upgrade is complete. Some temporary upgrade files could not be removed yet
                (a file may still be open, or the disk was full). Free some space or close the program,
                then retry the cleanup. It removes only temporary upgrade files; the installed site and its backup are kept.
                From a shell you can also run the upgrade bridge with <code>--cleanup={{ $state['upgrade_id'] }}</code>.
            </div>
            <form method="POST" action="{{ route('cms.upgrade.cleanup') }}" class="actions">
                @csrf
                <input type="hidden" name="upgrade_id" value="{{ $state['upgrade_id'] }}">
                <span></span>
                <button type="submit" class="btn btn--ghost">Retry cleanup</button>
            </form>
        @endif
        <div class="actions">
            <a class="btn btn--ghost" href="{{ url('/') }}">View site</a>
            <a class="btn btn--primary" href="{{ url(config('cms.admin_path', 'admin')) }}">Go to admin →</a>
        </div>
    @elseif ($status === \TheNguyen\CMS\Upgrade\UpgradeState::FAILED)
        <h1>Upgrade did not complete</h1>
        @php($recovered = $state['recovered'] ?? null)
        @php($outcome = $state['outcome'] ?? null)
        <div class="alert {{ $recovered ? 'alert--warn' : 'alert--error' }}">
            The upgrade failed at stage <strong>{{ str_replace('_', ' ', $state['failure_stage'] ?? 'unknown') }}</strong>.
            @if ($outcome === \TheNguyen\CMS\Upgrade\UpgradeOutcome::REJECTED)
                It was refused before anything was changed; your site is untouched.
            @elseif ($recovered === true)
                Your site was rolled back and verified: every Core file matches its pre-upgrade copy and it is running on the previous version.
            @elseif ($recovered === false)
                Automatic recovery could not be verified — <strong>recovery is required</strong>. New upgrades are blocked until it succeeds.
                Close programs that may hold site files open (editors, file explorers, terminals, sync or backup tools), then retry recovery.
            @endif
        </div>
        <div class="kv"><span class="label">Incident ID</span><span class="v"><code>{{ $state['upgrade_id'] ?? '—' }}</code></span></div>
        @if (! empty($state['failure_reason']))
            <div class="kv"><span class="label">Reason</span><span class="v">{{ $state['failure_reason'] }}</span></div>
        @endif
        @if (! empty($state['recovery_notes']))
            <div class="kv"><span class="label">Recovery</span><span class="v">{{ implode('; ', (array) $state['recovery_notes']) }}</span></div>
        @endif
        <div class="actions">
            <a class="btn btn--ghost" href="{{ url(config('cms.admin_path', 'admin')) }}">Back to admin</a>
            @if ($recovered === false)
                <form method="POST" action="{{ route('cms.upgrade.recover') }}">
                    @csrf
                    <button type="submit" class="btn btn--primary">Retry recovery</button>
                </form>
            @else
                <a class="btn btn--primary" href="{{ route('cms.upgrade.index') }}">Upgrade again →</a>
            @endif
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
