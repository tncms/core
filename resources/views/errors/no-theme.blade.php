<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>No active theme — {{ $brand ?? 'TN CMS' }}</title>
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
            code { background: rgba(255,255,255,.08); }
        }
        h1 { font-size: 1.4rem; margin-bottom: .5rem; }
        p { margin: .6rem 0; }
        code {
            background: rgba(0,0,0,.06);
            padding: .15rem .4rem;
            border-radius: 6px;
            font-size: .85em;
        }
        .card {
            border: 1px solid rgba(245,158,11,.4);
            background: rgba(245,158,11,.10);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>No active theme found</h1>
        <p>{{ $brand ?? 'TN CMS' }} has no valid theme to render the frontend with.</p>
        <p>
            Please activate a theme in the admin under
            <code>Appearance &rarr; Themes</code>, or add a valid theme folder
            (containing a <code>theme.json</code> with <code>name</code>,
            <code>slug</code>, <code>version</code>, and <code>author</code>) to the
            <code>themes/</code> directory.
        </p>
    </div>
</body>
</html>
