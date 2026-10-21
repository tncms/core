{{-- Theme preferences override. Frozen theme view name is
     theme::account.preferences (the Core fallback is cms::account.preferences).
     Self-contained; omits the robots meta to prove header-only noindex. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ __('Preferences') }}</title></head>
<body>
<h1 data-marker="theme-preferences-override">theme-preferences-override</h1>
<form method="POST" action="{{ route('cms.account.preferences.update') }}">
    @csrf
    <input type="text" name="timezone" value="{{ old('timezone', auth()->user()?->timezone) }}">
</form>
</body>
</html>
