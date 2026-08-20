{{-- Default Theme — master layout. --}}
@php($currentLanguage = language()->current())
<!DOCTYPE html>
<html lang="{{ current_locale() }}" dir="{{ $currentLanguage?->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($favicon = theme_option('favicon'))
    @php($favicon = is_string($favicon) && $favicon !== '' ? $favicon : settings('general.favicon'))
    @if (is_string($favicon) && $favicon !== '')
        <link rel="icon" href="{{ $favicon }}">
    @endif
    @include('theme::partials.seo')
    <link rel="stylesheet" href="{{ theme_asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ theme_asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ theme_asset('css/sections.css') }}">
    @php($primaryColor = theme_option('primary_color', '#2563eb'))
    @php($primaryColor = is_string($primaryColor) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $primaryColor) ? $primaryColor : '#2563eb')
    @php($secondaryColor = theme_option('secondary_color', '#0ea5e9'))
    @php($secondaryColor = is_string($secondaryColor) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $secondaryColor) ? $secondaryColor : '#0ea5e9')
    @php($sidebarWidth = tn_sidebar_width(theme_option('sidebar_width', 320)))
    <style>:root { --tncms-primary: {{ $primaryColor }}; --tncms-secondary: {{ $secondaryColor }}; --tn-sidebar-width: {{ $sidebarWidth }}px; }</style>
    {{-- Asset Registry (v1.0.0-beta.7.1.13.1): enqueued frontend stylesheets +
         head inline styles, in dependency order. Rendered after core theme
         styles so plugin/theme styles can override. Empty when none enqueued. --}}
    {!! render_frontend_styles() !!}
    {{-- Global Script Manager (v1.0.0-beta.7.1.13): meta, verification, JSON-LD,
         head scripts + embeds. Registered via Script::head() / register_meta()
         etc. Empty when nothing is registered. --}}
    {!! render_head_assets() !!}
</head>
<body>
    {{-- Hook points (v1.0.0-beta.7.1.11): plugins/themes echo into these via
         add_action('cms.theme.header', ...) etc. Empty when nothing registers. --}}
    {!! render_hook('cms.theme.header') !!}
    @include('theme::partials.header')
    <main class="site-main">
        {!! render_hook('cms.theme.before_content') !!}
        @yield('content')
        {!! render_hook('cms.theme.after_content') !!}
    </main>

    @include('theme::partials.footer')
    {!! render_hook('cms.theme.footer') !!}

    <script src="{{ theme_asset('js/app.js') }}" defer></script>
    <script src="{{ theme_asset('js/accordion.js') }}" defer></script>
    {{-- Asset Registry (v1.0.0-beta.7.1.13.1): enqueued frontend footer
         scripts/modules + footer inline scripts, in dependency order. Placed
         after core theme JS so plugin scripts can rely on it. Empty when none. --}}
    {!! render_frontend_scripts() !!}
    {{-- Global Script Manager (v1.0.0-beta.7.1.13): footer scripts + embeds.
         Placed after core theme JS so registered analytics/tag scripts load
         last. Empty when nothing is registered. --}}
    {!! render_footer_assets() !!}
</body>
</html>
