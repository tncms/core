@extends('upgrade.layout')

@section('title', 'Backup')

@section('content')
    <h1>Create a full backup</h1>
    <p class="lead">
        Before anything changes, {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} makes and
        <strong>verifies</strong> a complete backup of your database, Core files and
        <code>.env</code>. The upgrade cannot start until the backup is verified.
    </p>

    <ul class="checks">
        <li><span>Database (PHP-native, no shell required)</span><span class="val">included</span></li>
        <li><span>Core files (code, vendor, Core public assets, default theme)</span><span class="val">included</span></li>
        <li><span>Environment file (<code>.env</code>)</span><span class="val">included, protected</span></li>
    </ul>

    <p class="hint">This can take a moment on larger sites. Please don't close this tab.</p>

    <form method="POST" action="{{ route('cms.upgrade.backup.run') }}">
        @csrf
        <div class="actions">
            <a class="btn btn--ghost" href="{{ route('cms.upgrade.systemCheck') }}">← Back</a>
            <button type="submit" class="btn btn--primary">Create &amp; verify backup →</button>
        </div>
    </form>
@endsection
