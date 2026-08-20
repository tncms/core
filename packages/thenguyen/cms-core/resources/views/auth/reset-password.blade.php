@extends('cms::layouts.auth')

@section('title', __('Reset password'))
@section('subtitle', __('Choose a new password for your account.'))

@section('content')
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="tn-field">
            <label for="email">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autofocus autocomplete="username">
        </div>
        <div class="tn-field">
            <label for="password">{{ __('New password') }}</label>
            <input id="password" type="password" name="password" required autocomplete="new-password">
        </div>
        <div class="tn-field">
            <label for="password_confirmation">{{ __('Confirm new password') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="tn-btn">{{ __('Reset password') }}</button>
    </form>
@endsection
