{{-- Fixture theme login override. Deliberately a self-contained layout with NO
     robots meta, to prove the X-Robots-Tag HTTP header enforces noindex even
     when a custom theme forgets the tag. Carries $errors / old() / session to
     prove view-data parity through the override. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Theme Login</title></head>
<body>
<h1 data-marker="theme-login-override">theme-login-override</h1>
@if ($errors->any())
<ul class="theme-errors">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
@endif
@if (session('status'))<p class="theme-status">{{ session('status') }}</p>@endif
<form method="post" action="{{ route('cms.auth.login.attempt') }}">
    @csrf
    <input id="email" type="email" name="email" value="{{ old('email') }}">
    <input id="password" type="password" name="password">
    <button type="submit">{{ __('Sign in') }}</button>
</form>
</body>
</html>
