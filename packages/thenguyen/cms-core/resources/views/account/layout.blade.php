{{-- Account Foundation shell — self-contained core layout (v1.0.0-beta.7.1.15).
     Intentionally standalone (not coupled to a theme's master layout) so the
     account area always renders, even before/without an active theme. Pulls the
     active theme's token stylesheet + Asset/Script head/footer hooks when present.
     Plugins extend it through the cms.account.* render hooks and the
     cms.account.navigation_items filter — core adds no customer/order/plugin UI. --}}
@php
    $accountUser = auth()->user();
    $accountNav = app('cms.account')->navigationItems($accountUser, request());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', __('Account')) · {{ settings('general.site_name', config('app.name')) }}</title>
    <style>
        :root { --tn-acc-accent: #2563eb; --tn-acc-bg: #f4f5f7; --tn-acc-fg: #1f2933; --tn-acc-muted: #6b7280; --tn-acc-border: #d7dbe0; --tn-acc-surface: #fff; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--tn-acc-bg); color: var(--tn-acc-fg); }
        .tn-acc-shell { max-width: 64rem; margin: 0 auto; padding: 2rem 1rem; display: grid; grid-template-columns: 15rem 1fr; gap: 1.5rem; }
        @media (max-width: 48rem) { .tn-acc-shell { grid-template-columns: 1fr; } }
        .tn-acc-side { align-self: start; }
        .tn-acc-side .tn-acc-user { padding: 1rem; background: var(--tn-acc-surface); border: 1px solid var(--tn-acc-border); border-radius: 12px; margin-bottom: 1rem; }
        .tn-acc-side .tn-acc-user strong { display: block; }
        .tn-acc-side .tn-acc-user span { color: var(--tn-acc-muted); font-size: .82rem; word-break: break-all; }
        .tn-acc-nav { list-style: none; margin: 0; padding: .4rem; background: var(--tn-acc-surface); border: 1px solid var(--tn-acc-border); border-radius: 12px; }
        .tn-acc-nav a { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .55rem .7rem;
            border-radius: 8px; text-decoration: none; color: var(--tn-acc-fg); font-size: .92rem; }
        .tn-acc-nav a:hover { background: var(--tn-acc-bg); }
        .tn-acc-nav a[aria-current="page"] { background: var(--tn-acc-accent); color: #fff; font-weight: 600; }
        .tn-acc-nav .tn-acc-badge { background: #ef4444; color: #fff; border-radius: 999px; font-size: .7rem; padding: .05rem .45rem; }
        .tn-acc-main { min-width: 0; }
        .tn-acc-card { background: var(--tn-acc-surface); border: 1px solid var(--tn-acc-border); border-radius: 14px;
            padding: 1.5rem; box-shadow: 0 10px 30px rgba(15, 23, 42, .05); margin-bottom: 1.25rem; }
        .tn-acc-card h1, .tn-acc-card h2 { margin-top: 0; }
        .tn-acc-card h1 { font-size: 1.4rem; }
        .tn-acc-card h2 { font-size: 1.1rem; }
        .tn-acc-muted { color: var(--tn-acc-muted); }
        .tn-field { margin-bottom: 1rem; }
        .tn-field label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .35rem; }
        .tn-field input, .tn-field textarea, .tn-field select {
            width: 100%; padding: .6rem .7rem; border: 1px solid var(--tn-acc-border); border-radius: 8px; font-size: .95rem; font-family: inherit; }
        .tn-field input:focus, .tn-field textarea:focus, .tn-field select:focus { outline: 2px solid var(--tn-acc-accent); outline-offset: 1px; border-color: var(--tn-acc-accent); }
        .tn-field .tn-hint { color: var(--tn-acc-muted); font-size: .78rem; margin-top: .25rem; }
        .tn-btn { padding: .6rem 1rem; border: 0; border-radius: 8px; background: var(--tn-acc-accent); color: #fff; font-size: .92rem; font-weight: 600; cursor: pointer; }
        .tn-btn:hover { filter: brightness(.95); }
        .tn-btn-ghost { background: transparent; color: var(--tn-acc-accent); border: 1px solid var(--tn-acc-border); }
        .tn-alert { padding: .7rem .8rem; border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
        .tn-alert-status { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .tn-errors { margin: 0 0 1rem; padding-left: 1.1rem; color: #991b1b; font-size: .85rem; }
        .tn-dl { display: grid; grid-template-columns: max-content 1fr; gap: .35rem 1rem; font-size: .9rem; }
        .tn-dl dt { color: var(--tn-acc-muted); }
        .tn-dl dd { margin: 0; word-break: break-all; }
    </style>
    {!! function_exists('render_frontend_styles') ? render_frontend_styles() : '' !!}
    {!! function_exists('render_head_assets') ? render_head_assets() : '' !!}
</head>
<body>
    {!! render_hook('cms.account.before') !!}

    <div class="tn-acc-shell">
        <aside class="tn-acc-side">
            {!! render_hook('cms.account.sidebar.before') !!}

            <div class="tn-acc-user">
                <strong>{{ $accountUser?->name }}</strong>
                <span>{{ $accountUser?->email }}</span>
            </div>

            <nav aria-label="{{ __('Account navigation') }}">
                <ul class="tn-acc-nav">
                    @foreach ($accountNav as $item)
                        <li>
                            <a href="{{ $item->url }}" @if ($item->active) aria-current="page" @endif>
                                <span>{{ $item->label }}</span>
                                @if ($item->badge !== null)<span class="tn-acc-badge">{{ $item->badge }}</span>@endif
                            </a>
                        </li>
                    @endforeach
                </ul>
                {!! render_hook('cms.account.navigation') !!}
            </nav>

            <form method="POST" action="{{ route('cms.auth.logout') }}" style="margin-top:1rem;">
                @csrf
                <button type="submit" class="tn-btn tn-btn-ghost" style="width:100%;">{{ __('Sign out') }}</button>
            </form>

            {!! render_hook('cms.account.sidebar.after') !!}
        </aside>

        <main class="tn-acc-main">
            @if (session('status'))
                <div class="tn-alert tn-alert-status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <ul class="tn-errors">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif

            @yield('account_content')
        </main>
    </div>

    {!! render_hook('cms.account.after') !!}
    {!! function_exists('render_frontend_scripts') ? render_frontend_scripts() : '' !!}
    {!! function_exists('render_footer_assets') ? render_footer_assets() : '' !!}
</body>
</html>
