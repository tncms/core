<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Theme Forgot</title></head>
<body>
<h1 data-marker="theme-forgot-override">theme-forgot-override</h1>
@if (session('status'))<p class="theme-status">{{ session('status') }}</p>@endif
<form method="post" action="{{ route('password.email') }}">
    @csrf
    <input id="email" type="email" name="email" value="{{ old('email') }}">
    <button type="submit">{{ __('Email password reset link') }}</button>
</form>
</body>
</html>
