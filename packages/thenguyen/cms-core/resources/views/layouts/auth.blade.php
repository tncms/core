{{-- Frontend Auth — minimal, self-contained core layout (v1.0.0-beta.7.1.14).
     Intentionally standalone (not coupled to a theme's master layout) so auth
     pages always render even before/without an active theme. Pulls the active
     theme's token stylesheet when present, plus the Asset Registry / Script
     Manager head + footer hooks. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', __('Account')) · {{ settings('general.site_name', config('app.name')) }}</title>
    <style>
        :root { --tn-auth-accent: #2563eb; --tn-auth-bg: #f4f5f7; --tn-auth-fg: #1f2933; --tn-auth-muted: #6b7280; --tn-auth-border: #d7dbe0; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--tn-auth-bg); color: var(--tn-auth-fg); padding: 2rem 1rem; }
        .tn-auth-card { width: 100%; max-width: 26rem; background: #fff; border: 1px solid var(--tn-auth-border);
            border-radius: 14px; padding: 2rem; box-shadow: 0 10px 30px rgba(15, 23, 42, .06); }
        .tn-auth-card h1 { margin: 0 0 .35rem; font-size: 1.5rem; }
        .tn-auth-sub { margin: 0 0 1.5rem; color: var(--tn-auth-muted); font-size: .9rem; }
        .tn-field { margin-bottom: 1rem; }
        .tn-field label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .35rem; }
        .tn-field input[type="text"], .tn-field input[type="email"], .tn-field input[type="password"] {
            width: 100%; padding: .6rem .7rem; border: 1px solid var(--tn-auth-border); border-radius: 8px; font-size: .95rem; }
        .tn-field input:focus { outline: 2px solid var(--tn-auth-accent); outline-offset: 1px; border-color: var(--tn-auth-accent); }
        .tn-check { display: flex; align-items: center; gap: .5rem; font-size: .9rem; margin-bottom: 1rem; }
        .tn-btn { width: 100%; padding: .7rem; border: 0; border-radius: 8px; background: var(--tn-auth-accent); color: #fff;
            font-size: .95rem; font-weight: 600; cursor: pointer; }
        .tn-btn:hover { filter: brightness(.95); }
        .tn-links { margin-top: 1.25rem; display: flex; justify-content: space-between; font-size: .85rem; }
        .tn-links a { color: var(--tn-auth-accent); text-decoration: none; }
        .tn-alert { padding: .7rem .8rem; border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
        .tn-alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .tn-alert-status { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .tn-errors { margin: 0 0 1rem; padding-left: 1.1rem; color: #991b1b; font-size: .85rem; }
    </style>
    {!! function_exists('render_frontend_styles') ? render_frontend_styles() : '' !!}
    {!! function_exists('render_head_assets') ? render_head_assets() : '' !!}
</head>
<body>
    <main class="tn-auth-card">
        <h1>@yield('title', __('Account'))</h1>
        @hasSection('subtitle')<p class="tn-auth-sub">@yield('subtitle')</p>@endif

        @if (session('status'))
            <div class="tn-alert tn-alert-status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <ul class="tn-errors">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif

        @yield('content')
    </main>
    {!! function_exists('render_frontend_scripts') ? render_frontend_scripts() : '' !!}
    {!! function_exists('render_footer_assets') ? render_footer_assets() : '' !!}
</body>
</html>
