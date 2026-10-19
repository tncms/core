{{-- Theme account override. Frozen theme view name is theme::account.index
     (the Core fallback is cms::account.dashboard). --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Theme Account</title></head>
<body>
<h1 data-marker="theme-account-override">theme-account-override</h1>
<p class="theme-account-user">{{ auth()->user()?->name }}</p>
</body>
</html>
