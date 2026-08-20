@extends('cms::account.layout')

@section('title', __('Security'))

@section('account_content')
    @php($user = auth()->user())
    <div class="tn-acc-card">
        <h1>{{ __('Change password') }}</h1>
        <p class="tn-acc-muted">{{ __('Changing your password signs out your other sessions.') }}</p>
        <form method="POST" action="{{ route('cms.account.security.password') }}">
            @csrf
            <div class="tn-field">
                <label for="password_current">{{ __('Current password') }}</label>
                <input id="password_current" type="password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="tn-field">
                <label for="password">{{ __('New password') }}</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                <div class="tn-hint">{{ __('At least 8 characters.') }}</div>
            </div>
            <div class="tn-field">
                <label for="password_confirmation">{{ __('Confirm new password') }}</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>
            <button type="submit" class="tn-btn">{{ __('Update password') }}</button>
        </form>
    </div>

    <div class="tn-acc-card">
        <h2>{{ __('Change email') }}</h2>
        <p class="tn-acc-muted">{{ __('Current email: :email', ['email' => $user?->email]) }}</p>
        <form method="POST" action="{{ route('cms.account.security.email') }}">
            @csrf
            <div class="tn-field">
                <label for="email">{{ __('New email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            </div>
            <div class="tn-field">
                <label for="email_current_password">{{ __('Current password') }}</label>
                <input id="email_current_password" type="password" name="current_password" required autocomplete="current-password">
            </div>
            <button type="submit" class="tn-btn">{{ __('Update email') }}</button>
        </form>
    </div>

    {!! render_hook('cms.account.security.after') !!}
@endsection
