{{-- Disposable theme override of theme::account.profile for P3. It renders the
     Core dynamic profile-fields seam via @include WITHOUT duplicating any
     authorization/validation logic, proving the documented integration point. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ __('Profile') }}</title></head>
<body>
<h1 data-marker="theme-profile-override">theme-profile-override</h1>

@include('cms::account.partials.profile-fields')
</body>
</html>
