@extends('cms::layouts.auth')

@section('title', __('Forgot password'))
@section('subtitle', __('Enter your email and we will send a reset link.'))

@section('content')
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="tn-field">
            <label for="email">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <button type="submit" class="tn-btn">{{ __('Send reset link') }}</button>
    </form>
    <div class="tn-links">
        <a href="{{ route('cms.auth.login') }}">{{ __('Back to sign in') }}</a>
    </div>
@endsection
