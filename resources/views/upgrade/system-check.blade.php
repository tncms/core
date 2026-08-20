@extends('upgrade.layout')

@section('title', 'System Check')

@section('content')
    <h1>System check</h1>
    <p class="lead">
        Verifying your environment before any backup or change is made. All required
        checks must pass to continue.
    </p>

    @php($target = $state['target_version'] ?? null)
    @if ($target)
        <div class="kv">
            <span class="label">This upgrade</span>
            <span class="v">v{{ $state['source_version'] ?? '?' }} <span class="ver-arrow">→</span> v{{ $target }}</span>
        </div>
    @endif

    <ul class="checks">
        @foreach ($checks as $check)
            <li>
                <span>{{ $check['name'] }}@if ($check['detail']) <span class="val"> — {{ $check['detail'] }}</span>@endif</span>
                <span class="badge {{ $check['ok'] ? 'badge--ok' : 'badge--bad' }}">{{ $check['ok'] ? 'OK' : 'FAIL' }}</span>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('cms.upgrade.systemCheck.run') }}">
        @csrf
        <div class="actions">
            <a class="btn btn--ghost" href="{{ route('cms.upgrade.index') }}">← Cancel</a>
            <button type="submit" class="btn btn--primary" @disabled(! $passed)>Continue to backup →</button>
        </div>
    </form>
@endsection
