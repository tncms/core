@extends('cms::layouts.auth')

@section('title', __('Verify your email'))
@section('subtitle', __('We sent a verification link to your email address.'))

@section('content')
    <p class="tn-auth-sub">
        {{ __('Before continuing, please check your inbox for the verification link. If you did not receive it, request another below.') }}
    </p>
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="tn-btn">{{ __('Resend verification email') }}</button>
    </form>
    <div class="tn-links">
        <form method="POST" action="{{ route('cms.auth.logout') }}">
            @csrf
            <button type="submit" style="background:none;border:0;color:var(--tn-auth-accent);cursor:pointer;padding:0;font-size:.85rem;">{{ __('Sign out') }}</button>
        </form>
    </div>
@endsection
