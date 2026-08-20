@extends('cms::account.layout')

@section('title', __('Dashboard'))

@section('account_content')
    {!! render_hook('cms.account.dashboard.before') !!}

    <div class="tn-acc-card">
        <h1>{{ __('Hello, :name', ['name' => auth()->user()?->name]) }}</h1>
        <p class="tn-acc-muted">{{ __('Manage your account details, security, and preferences.') }}</p>
    </div>

    <div class="tn-acc-card">
        <h2>{{ __('Account overview') }}</h2>
        <dl class="tn-dl">
            <dt>{{ __('Name') }}</dt><dd>{{ auth()->user()?->name }}</dd>
            <dt>{{ __('Email') }}</dt><dd>{{ auth()->user()?->email }}</dd>
            @if (auth()->user()?->username)
                <dt>{{ __('Username') }}</dt><dd>{{ auth()->user()->username }}</dd>
            @endif
        </dl>
        <p style="margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap;">
            <a class="tn-btn tn-btn-ghost" href="{{ route('cms.account.profile') }}">{{ __('Profile') }}</a>
            <a class="tn-btn tn-btn-ghost" href="{{ route('cms.account.security') }}">{{ __('Security') }}</a>
            <a class="tn-btn tn-btn-ghost" href="{{ route('cms.account.sessions') }}">{{ __('Sessions') }}</a>
            <a class="tn-btn tn-btn-ghost" href="{{ route('cms.account.preferences') }}">{{ __('Preferences') }}</a>
        </p>
    </div>

    {!! render_hook('cms.account.dashboard.after') !!}
@endsection
