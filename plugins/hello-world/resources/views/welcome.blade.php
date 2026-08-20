<!DOCTYPE html>
<html lang="{{ current_locale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hello World — TN CMS Plugin</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1.25rem; line-height: 1.55; }
        .card { border: 1px solid rgba(16,185,129,.4); background: rgba(16,185,129,.10); border-radius: 12px; padding: 1.25rem 1.5rem; }
        code { background: rgba(0,0,0,.06); padding: .15rem .4rem; border-radius: 6px; font-size: .85em; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ plugin_trans('hello-world', 'Hello World from TN CMS Plugin') }}</h1>
        <p>This page is rendered from the <code>hello-world</code> plugin via the
            <code>hello-world::welcome</code> view namespace.</p>
    </div>
</body>
</html>
