{{-- Fixture theme verify-email override (CORE-40-ACCOUNT-SHELL-LOCALE-R1). Self-contained,
     with NO robots meta, to prove the X-Robots-Tag header still enforces noindex. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Theme Verify</title></head>
<body>
<h1 data-marker="theme-verify-email-override">theme-verify-email-override</h1>
<form method="POST" action="{{ cms_localized_route('verification.send') }}">@csrf<button type="submit">resend</button></form>
</body>
</html>
