@extends('cms::account.layout')

@section('title', __('Sessions'))

@section('account_content')
    @php($user = auth()->user())
    <div class="tn-acc-card">
        <h1>{{ __('Sessions') }}</h1>
        <p class="tn-acc-muted">{{ __('This is a foundation-level view of your current session. There is no full device registry.') }}</p>
        <dl class="tn-dl">
            <dt>{{ __('Last login') }}</dt>
            <dd>{{ $user?->frontend_last_login_at?->diffForHumans() ?? __('—') }}</dd>
            <dt>{{ __('Last login IP') }}</dt>
            <dd>{{ $user?->frontend_last_login_ip ?? __('—') }}</dd>
            <dt>{{ __('Device signature') }}</dt>
            <dd>{{ $user?->frontend_last_user_agent_hash ? substr($user->frontend_last_user_agent_hash, 0, 12) . '…' : __('—') }}</dd>
            <dt>{{ __('Session version') }}</dt>
            <dd>{{ $user?->frontend_session_version }}</dd>
        </dl>
    </div>

    <div class="tn-acc-card">
        <h2>{{ __('Log out other sessions') }}</h2>
        <p class="tn-acc-muted">{{ __('Sign out everywhere except this browser.') }}</p>
        <form method="POST" action="{{ route('cms.account.sessions.logout-others') }}">
            @csrf
            <div class="tn-field">
                <label for="sessions_current_password">{{ __('Current password') }}</label>
                <input id="sessions_current_password" type="password" name="current_password" required autocomplete="current-password">
            </div>
            <button type="submit" class="tn-btn">{{ __('Log out other sessions') }}</button>
        </form>
    </div>
@endsection
