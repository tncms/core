@extends('upgrade.layout')

@section('title', 'Ready')

@section('content')
    <h1>Ready to upgrade</h1>

    <div class="alert alert--ok">
        Your backup has been created and verified. You can now apply the upgrade safely.
    </div>

    @php($backup = $state['backup'] ?? [])
    <div class="kv">
        <span class="label">Target version</span>
        <span class="v">v{{ $state['source_version'] ?? '?' }} <span class="ver-arrow">→</span> v{{ $state['target_version'] ?? '?' }}</span>
    </div>
    <div class="kv">
        <span class="label">Backup — database</span>
        <span class="v">{{ ($backup['db_tables'] ?? 0) }} tables, {{ number_format($backup['db_rows'] ?? 0) }} rows</span>
    </div>
    <div class="kv">
        <span class="label">Backup — Core files</span>
        <span class="v">{{ number_format($backup['files_count'] ?? 0) }} files</span>
    </div>

    <p class="lead" style="margin-top:22px">
        When you start, the site enters maintenance mode, Core release state is cleanly
        replaced (obsolete Core files removed), migrations and caches run, and health is
        checked. On any failure the site is automatically rolled back from this backup.
    </p>

    <form method="POST" action="{{ route('cms.upgrade.start') }}" onsubmit="this.querySelector('button').disabled=true">
        @csrf
        <div class="actions">
            <a class="btn btn--ghost" href="{{ route('cms.upgrade.index') }}">← Cancel</a>
            <button type="submit" class="btn btn--primary">Start upgrade →</button>
        </div>
    </form>
@endsection
