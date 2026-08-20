@extends('install.layout')

@section('title', 'Requirements')

@section('content')
    <h1>Server requirements</h1>
    <p class="lead">
        {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} needs the following to run. Resolve any
        failing item before continuing.
    </p>

    @unless ($passed)
        <div class="alert alert--error">
            Some required checks did not pass. Fix them, then reload this page.
        </div>
    @endunless

    <ul class="checks">
        @foreach ($checks as $check)
            <li>
                <span>
                    {{ $check['label'] }}
                    <span class="val">— {{ $check['value'] }}</span>
                </span>
                @if ($check['passed'])
                    <span class="badge badge--ok">OK</span>
                @else
                    <span class="badge badge--bad">FAIL</span>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="actions">
        <a class="btn btn--ghost" href="{{ route('cms.install.welcome') }}">← Back</a>
        @if ($passed)
            <a class="btn btn--primary" href="{{ route('cms.install.database') }}">Continue →</a>
        @else
            <button class="btn btn--primary" disabled>Continue →</button>
        @endif
    </div>
@endsection
