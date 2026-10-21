{{-- Theme sessions override. Frozen theme view name is theme::account.sessions
     (the Core fallback is cms::account.sessions). Self-contained on purpose and
     intentionally OMITS <meta name="robots"> to prove the X-Robots-Tag response
     header alone enforces noindex. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ __('Sessions') }}</title></head>
<body>
<h1 data-marker="theme-sessions-override">theme-sessions-override</h1>
<form method="POST" action="{{ route('cms.account.sessions.logout-others') }}">
    @csrf
    <input type="password" name="current_password">
    @if ($errors->any())<ul class="theme-errors">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
</form>
</body>
</html>
