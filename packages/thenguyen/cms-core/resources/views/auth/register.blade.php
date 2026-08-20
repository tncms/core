@extends('cms::layouts.auth')

@section('title', __('Create account'))
@section('subtitle', __('Register to get started.'))

@section('content')
    <form method="POST" action="{{ route('cms.auth.register.store') }}">
        @csrf
        <div class="tn-field">
            <label for="name">{{ __('Name') }}</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
        </div>
        <div class="tn-field">
            <label for="email">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
        </div>
        <div class="tn-field">
            <label for="password">{{ __('Password') }}</label>
            <input id="password" type="password" name="password" required autocomplete="new-password">
        </div>
        <div class="tn-field">
            <label for="password_confirmation">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="tn-btn">{{ __('Create account') }}</button>
    </form>
    <div class="tn-links">
        <a href="{{ route('cms.auth.login') }}">{{ __('Already have an account? Sign in') }}</a>
    </div>
@endsection
