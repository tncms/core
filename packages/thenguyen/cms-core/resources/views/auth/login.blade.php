@extends('cms::layouts.auth')

@section('title', __('Sign in'))
@section('subtitle', __('Welcome back. Please sign in to continue.'))

@section('content')
    <form method="POST" action="{{ route('cms.auth.login.attempt') }}">
        @csrf
        <div class="tn-field">
            <label for="email">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div class="tn-field">
            <label for="password">{{ __('Password') }}</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>
        <label class="tn-check">
            <input type="checkbox" name="remember" value="1"> {{ __('Remember me') }}
        </label>
        <button type="submit" class="tn-btn">{{ __('Sign in') }}</button>
    </form>
    <div class="tn-links">
        @if (Route::has('password.request'))
            <a href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a>
        @endif
        @if (frontend_auth()->registrationEnabled())
            <a href="{{ route('cms.auth.register') }}">{{ __('Create account') }}</a>
        @endif
    </div>
@endsection
