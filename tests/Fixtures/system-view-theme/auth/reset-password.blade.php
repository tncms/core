{{-- Echoes $token and $email to prove the controller's view data reaches the
     theme override unchanged. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Theme Reset</title></head>
<body>
<h1 data-marker="theme-reset-override">theme-reset-override</h1>
<span data-token="{{ $token }}">{{ $token }}</span>
<span data-email="{{ $email }}">{{ $email }}</span>
<form method="post" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <input id="email" type="email" name="email" value="{{ $email }}">
    <input id="password" type="password" name="password">
    <input id="password_confirmation" type="password" name="password_confirmation">
    <button type="submit">{{ __('Reset password') }}</button>
</form>
</body>
</html>
