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
    {{-- Core theme stylesheets are declared in theme.json "assets" and rendered
         here through the Asset Registry in dependency order (tokens → app →
         sections), alongside enqueued plugin/theme styles (EG-6 declarative
         manifest, v1.0.0-beta.7.1.24). Empty only when nothing is enqueued. --}}
    {!! render_frontend_styles() !!}
    @php($primaryColor = theme_option('primary_color', '#2563eb'))
    @php($primaryColor = is_string($primaryColor) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $primaryColor) ? $primaryColor : '#2563eb')
    @php($secondaryColor = theme_option('secondary_color', '#0ea5e9'))
    @php($secondaryColor = is_string($secondaryColor) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $secondaryColor) ? $secondaryColor : '#0ea5e9')
    @php($sidebarWidth = tn_sidebar_width(theme_option('sidebar_width', 320)))
    {{-- Theme Option overrides come AFTER the core stylesheets so the admin's
         primary/secondary colour and sidebar width win over tokens.css defaults. --}}
    <style>:root { --tncms-primary: {{ $primaryColor }}; --tncms-secondary: {{ $secondaryColor }}; --tn-sidebar-width: {{ $sidebarWidth }}px; }</style>
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

    {{-- Core theme scripts (js/app.js, js/accordion.js) are declared in
         theme.json "assets" (deferred, footer) and rendered here through the
         Asset Registry in dependency order, alongside enqueued plugin/theme
         footer scripts (EG-6 declarative manifest). Empty when none enqueued. --}}
    {!! render_frontend_scripts() !!}
    {{-- Global Script Manager (v1.0.0-beta.7.1.13): footer scripts + embeds.
         Placed after core theme JS so registered analytics/tag scripts load
         last. Empty when nothing is registered. --}}
    {!! render_footer_assets() !!}
</body>
</html>
