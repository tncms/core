{{-- Default Theme — maintenance view (v1.0.0-beta.4).

     Optional: themes MAY provide views/maintenance.blade.php. The CMS renders it
     when maintenance mode is on (mode = theme). It is intentionally standalone
     (no header/footer, no admin links) and minimal. The $title, $message,
     $statusCode, $retryAfterMinutes, $siteName and $homeUrl variables are
     supplied by MaintenanceManager. --}}
<!DOCTYPE html>
<html lang="{{ current_locale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title }} — {{ $siteName }}</title>
    @php($favicon = settings('general.favicon'))
    @if (is_string($favicon) && $favicon !== '')
        <link rel="icon" href="{{ $favicon }}">
    @endif
    @php($primaryColor = theme_option('primary_color', '#2563eb'))
    @php($primaryColor = is_string($primaryColor) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $primaryColor) ? $primaryColor : '#2563eb')
    <style>
        :root { color-scheme: light dark; --tncms-primary: {{ $primaryColor }}; }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh; margin: 0; display: flex; align-items: center;
            justify-content: center; text-align: center; padding: 1.5rem;
            color: #1f2937; background: #f9fafb;
        }
        @media (prefers-color-scheme: dark) {
            body { color: #e5e7eb; background: #0b0f19; }
        }
        .wrap { max-width: 34rem; }
        .brand {
            font-weight: 700; letter-spacing: .04em; color: var(--tncms-primary);
            text-transform: uppercase; font-size: .8rem; margin-bottom: 1.5rem;
        }
        h1 { font-size: clamp(1.8rem, 1.3rem + 2.4vw, 2.6rem); margin: 0 0 1rem; line-height: 1.12; }
        p { line-height: 1.65; font-size: 1.05rem; margin: .5rem 0; }
        .retry { margin-top: 1.5rem; font-size: .9rem; opacity: .7; }
        .bar { height: 4px; width: 80px; border-radius: 99px; background: var(--tncms-primary); margin: 1.75rem auto 0; opacity: .85; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">{{ $siteName }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if (! is_null($retryAfterMinutes))
            <p class="retry">We expect to be back in about {{ $retryAfterMinutes }} minutes.</p>
        @endif
        <div class="bar"></div>
    </div>
</body>
</html>
