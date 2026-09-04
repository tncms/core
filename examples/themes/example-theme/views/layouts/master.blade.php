{{-- Theme Example — master layout. The theme owns presentation only; every
     dynamic value comes escaped from Core ViewModels or the documented helpers. --}}
<!DOCTYPE html>
<html lang="{{ current_locale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('theme::partials.seo')
    {{-- Stylesheets declared in theme.json "assets", rendered by Core in
         dependency order with owner-aware URLs (EG-6). --}}
    {!! render_frontend_styles() !!}
    {!! render_head_assets() !!}
</head>
<body>
    {!! render_hook('cms.theme.header') !!}
    <main class="site-main">
        {!! render_hook('cms.theme.before_content') !!}
        @yield('content')
        {!! render_hook('cms.theme.after_content') !!}
    </main>
    {!! render_hook('cms.theme.footer') !!}
    {!! render_frontend_scripts() !!}
</body>
</html>
