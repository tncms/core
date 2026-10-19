<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Theme Register</title></head>
<body>
<h1 data-marker="theme-register-override">theme-register-override</h1>
<form method="post" action="{{ route('cms.auth.register.store') }}">
    @csrf
    <input id="name" type="text" name="name" value="{{ old('name') }}">
    <input id="email" type="email" name="email" value="{{ old('email') }}">
    <input id="password" type="password" name="password">
    <input id="password_confirmation" type="password" name="password_confirmation">
    <button type="submit">{{ __('Create account') }}</button>
</form>
</body>
</html>
