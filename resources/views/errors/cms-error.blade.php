{{--
    Core self-contained error fallback (CORE-FRONTEND-1).

    Rendered by TheNguyen\CMS\Http\FrontendErrorResponder ONLY when the active
    theme ships no usable errors.{status}/errors.error view, or when rendering
    one threw. It must never depend on the theme (that is exactly what may be
    broken), so it carries its own minimal, inline-styled markup — mirroring
    resources/views/errors/no-theme.blade.php. All values are pre-sanitized in
    PHP and escaped here; no exception detail is ever exposed.

    Variables: $status, $title, $message, $brand, $homeUrl, $searchUrl,
               $homeLabel, $searchLabel
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,follow">
    <title>{{ $title }} — {{ $brand }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            max-width: 40rem;
            margin: 4rem auto;
            padding: 0 1.25rem;
            line-height: 1.55;
            color: #1f2937;
            background: #f9fafb;
        }
        @media (prefers-color-scheme: dark) {
            body { color: #e5e7eb; background: #0b0f19; }
            .card { background: rgba(255,255,255,.04); }
        }
        .status { font-size: 3rem; font-weight: 700; margin: 0; line-height: 1; opacity: .5; }
        h1 { font-size: 1.5rem; margin: .35rem 0 .5rem; }
        p { margin: .6rem 0; }
        .card {
            border: 1px solid rgba(148,163,184,.35);
            border-radius: 12px;
            padding: 1.5rem 1.75rem;
        }
        .actions { margin-top: 1.25rem; display: flex; flex-wrap: wrap; gap: .75rem; }
        .actions a {
            display: inline-block;
            padding: .5rem .9rem;
            border-radius: 8px;
            border: 1px solid rgba(148,163,184,.5);
            text-decoration: none;
            color: inherit;
        }
        form { margin-top: 1.25rem; display: flex; gap: .5rem; }
        input[type="search"] {
            flex: 1;
            padding: .5rem .75rem;
            border-radius: 8px;
            border: 1px solid rgba(148,163,184,.5);
            background: transparent;
            color: inherit;
        }
        button {
            padding: .5rem .9rem;
            border-radius: 8px;
            border: 1px solid rgba(148,163,184,.5);
            background: transparent;
            color: inherit;
            cursor: pointer;
        }
        .visually-hidden {
            position: absolute; width: 1px; height: 1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
        }
    </style>
</head>
<body>
    <main class="card">
        <p class="status">{{ $status }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        @if (! empty($searchUrl))
            <form method="GET" action="{{ $searchUrl }}" role="search">
                <label class="visually-hidden" for="cms-error-search">{{ $searchLabel }}</label>
                <input type="search" id="cms-error-search" name="q" placeholder="{{ $searchLabel }}" autocomplete="off">
                <button type="submit">{{ core_trans('Search') }}</button>
            </form>
        @endif

        <div class="actions">
            <a href="{{ $homeUrl }}">{{ $homeLabel }}</a>
        </div>
    </main>
</body>
</html>
