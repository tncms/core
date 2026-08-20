{{-- Core fallback maintenance view (v1.0.0-beta.4). Used when the active theme
     provides no views/maintenance.blade.php. Clean, responsive, no admin links. --}}
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
    <style>
        :root { color-scheme: light dark; --accent: #2563eb; }
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
        .card { max-width: 34rem; }
        .badge {
            display: inline-block; text-transform: uppercase; letter-spacing: .14em;
            font-size: .72rem; font-weight: 600; opacity: .65; margin-bottom: 1.25rem;
        }
        h1 { font-size: clamp(1.6rem, 1.2rem + 2vw, 2.4rem); margin: 0 0 1rem; line-height: 1.15; }
        p { line-height: 1.65; margin: .5rem 0; font-size: 1.05rem; }
        .retry { margin-top: 1.5rem; font-size: .9rem; opacity: .7; }
        .dot {
            display: inline-block; width: .55rem; height: .55rem; border-radius: 50%;
            background: var(--accent); margin-right: .5rem; vertical-align: middle;
            animation: pulse 1.6s ease-in-out infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: .4; } 50% { opacity: 1; } }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge"><span class="dot"></span>{{ $siteName }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if (! is_null($retryAfterMinutes))
            <p class="retry">{{ tn_trans('Please check back in about :minutes minutes.', ['minutes' => $retryAfterMinutes]) }}</p>
        @endif
    </div>
</body>
</html>
