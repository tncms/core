@extends('upgrade.layout')

@section('title', 'Updates')

@section('content')
    <h1>Software updates</h1>
    <p class="lead">
        This page reports the installed version and, when an update feed is
        configured, the latest available Core release for your channel. Updates
        are applied manually from the
        <a href="{{ route('cms.upgrade.index') }}">Core Upgrade wizard</a> — this
        screen never downloads or installs anything on its own.
    </p>

    <div class="kv">
        <span class="label">Installed version</span>
        <span class="v">v{{ $availability->currentVersion }}</span>
    </div>
    <div class="kv">
        <span class="label">Channel</span>
        <span class="v">{{ ucfirst($channel) }}</span>
    </div>
    <div class="kv">
        <span class="label">Latest version</span>
        <span class="v">{{ $availability->latestVersion ? 'v'.$availability->latestVersion : '—' }}</span>
    </div>

    @switch($availability->state)
        @case(\TheNguyen\CMS\Update\UpdateAvailability::UPDATE_AVAILABLE)
            <div class="alert alert--ok">
                A newer release is available:
                <strong>v{{ $availability->currentVersion }}</strong>
                <span class="ver-arrow">→</span>
                <strong>v{{ $availability->latestVersion }}</strong>.
            </div>
            <div class="actions">
                <span></span>
                <a class="btn btn--primary" href="{{ route('cms.upgrade.index') }}">Go to the upgrade wizard →</a>
            </div>
            @break

        @case(\TheNguyen\CMS\Update\UpdateAvailability::UP_TO_DATE)
            <div class="alert alert--ok">You are running the latest release on the {{ $channel }} channel.</div>
            @break

        @case(\TheNguyen\CMS\Update\UpdateAvailability::INCOMPATIBLE)
            <div class="alert alert--warn">
                A newer release (v{{ $availability->latestVersion }}) exists but is not compatible with this
                installation: {{ $availability->detail }}
            </div>
            @break

        @default
            <div class="alert alert--warn">
                Update status is unavailable{{ $availability->detail ? ': '.$availability->detail : '.' }}
            </div>
    @endswitch

    <div class="actions">
        <a class="btn btn--ghost" href="{{ url(config('cms.admin_path', 'admin')) }}">← Back to admin</a>
        <a class="btn btn--ghost" href="{{ route('cms.upgrade.updates') }}">Re-check</a>
    </div>
@endsection
