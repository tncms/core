{{-- Fixture theme::account.layout (CORE-40-ACCOUNT-SHELL-LOCALE-R1): a theme shell that
     follows the documented contract using Core's partials. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', __('Account')) · theme-shell</title>
</head>
<body>
<header data-marker="theme-account-shell">theme-account-shell header</header>
{!! render_hook('cms.account.before') !!}
<div class="theme-account-grid">
    <aside class="theme-account-side">
        @include('cms::account.partials.sidebar')
    </aside>
    <main class="theme-account-main">
        @include('cms::account.partials.flash')
        @yield('account_content')
    </main>
</div>
{!! render_hook('cms.account.after') !!}
<footer>theme-account-shell footer</footer>
</body>
</html>
