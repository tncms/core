{{-- Theme profile override. Frozen theme view name is theme::account.profile
     (the Core fallback is cms::account.profile). Self-contained on purpose and
     intentionally OMITS <meta name="robots"> to prove the X-Robots-Tag response
     header alone enforces noindex. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ __('Profile') }}</title></head>
<body>
<h1 data-marker="theme-profile-override">theme-profile-override</h1>
<form method="POST" action="{{ route('cms.account.profile.update') }}">
    @csrf
    <input type="text" name="name" value="{{ old('name', auth()->user()?->name) }}">
    @if ($errors->any())<ul class="theme-errors">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
</form>
</body>
</html>
