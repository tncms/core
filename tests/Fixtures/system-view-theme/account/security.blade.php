{{-- Theme security override. Frozen theme view name is theme::account.security
     (the Core fallback is cms::account.security). Self-contained; omits the
     robots meta to prove header-only noindex enforcement. Never echoes a
     password value. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ __('Security') }}</title></head>
<body>
<h1 data-marker="theme-security-override">theme-security-override</h1>
<form method="POST" action="{{ route('cms.account.security.password') }}">
    @csrf
    <input id="current_password" type="password" name="current_password" autocomplete="current-password">
    <input id="password" type="password" name="password" autocomplete="new-password">
    <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">
</form>
</body>
</html>
