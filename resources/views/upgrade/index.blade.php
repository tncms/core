@extends('upgrade.layout')

@section('title', 'Package')

@section('content')
    <h1>Upgrade {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }}</h1>
    <p class="lead">
        Upload an official Core upgrade package. The wizard verifies the package,
        checks your server, creates and verifies a full backup, and only then lets
        you apply the upgrade. Your content, uploads, <code>.env</code>, non-Core
        plugins and custom themes are preserved.
    </p>

    <div class="kv">
        <span class="label">Installed version</span>
        <span class="v">v{{ $current }}</span>
    </div>
    @if (($usage['attempts'] ?? 0) > 0)
        <div class="kv">
            <span class="label">Upgrade data on disk</span>
            <span class="v">{{ number_format(($usage['bytes'] ?? 0) / 1048576, 1) }} MiB in {{ $usage['attempts'] }} attempt(s)</span>
        </div>
    @endif

    @if ($situation['has_active'])
        @php($active = $situation['state'])
        @if (($active['outcome'] ?? null) === \TheNguyen\CMS\Upgrade\UpgradeOutcome::RECOVERY_REQUIRED)
            <div class="alert alert--error">
                An earlier upgrade could not be rolled back completely and still needs recovery
                (incident <code>{{ $active['upgrade_id'] }}</code>). New upgrades are blocked until
                recovery is verified. Close programs that may hold site files open (editors, file
                explorers, terminals, sync or backup tools), then retry.
            </div>
        @else
            <div class="alert alert--warn">
                An earlier upgrade attempt is still in progress
                (stage: <strong>{{ str_replace('_', ' ', $active['status']) }}</strong>).
                Continue it, or discard it to start over.
            </div>
        @endif
        <div class="actions">
            @if ($situation['recommendation'] === 'recover')
                <form method="POST" action="{{ route('cms.upgrade.recover') }}">
                    @csrf
                    <button type="submit" class="btn btn--primary">Recover now</button>
                </form>
            @else
                <a class="btn btn--primary" href="{{ route($stepRoute($active['status'])) }}">Continue →</a>
                <form method="POST" action="{{ route('cms.upgrade.recover') }}">
                    @csrf
                    <button type="submit" class="btn btn--ghost">Discard attempt</button>
                </form>
            @endif
        </div>
    @else
        <form method="POST" action="{{ route('cms.upgrade.package') }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label for="package">Official upgrade package (.zip)</label>
                <input type="file" id="package" name="package" accept=".zip" required>
                <p class="hint">Only official <code>tncms-*-upgrade.zip</code> packages are accepted. Never extract this over your site manually.</p>
            </div>
            <div class="actions">
                <a class="btn btn--ghost" href="{{ url(config('cms.admin_path', 'admin')) }}">← Back to admin</a>
                <button type="submit" class="btn btn--primary">Verify package →</button>
            </div>
        </form>
    @endif
@endsection
