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

    @if ($situation['has_active'])
        @php($active = $situation['state'])
        <div class="alert alert--warn">
            An earlier upgrade attempt is still in progress
            (stage: <strong>{{ str_replace('_', ' ', $active['status']) }}</strong>).
            Continue it, or discard it to start over.
        </div>
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
