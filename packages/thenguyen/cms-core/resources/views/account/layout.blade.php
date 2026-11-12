{{-- Account layout — the STABLE extend target for every account page, Core's and
     plugins' (CORE-40-ACCOUNT-SHELL-LOCALE-R1). It renders no markup itself: it
     prepares the shared contract data and extends the resolved shell —

       theme::account.layout   when the active theme ships one
       cms::account.shell      otherwise (Core's self-contained shell, unchanged)

     so `@extends('cms::account.layout')` keeps working and picks up the theme shell
     with no plugin change. Contract for a theme layout (see
     docs/frontend/SYSTEM-PAGE-VIEW-RESOLUTION.md):
       - @yield('account_content') and @yield('title');
       - $accountUser and $accountNav (navigation items, active state included);
       - the cms.account.before / cms.account.after render areas;
       - @include('cms::account.partials.sidebar') (user, nav, sidebar render
         areas, CSRF sign-out) and @include('cms::account.partials.flash'),
         or equivalent markup;
       - <meta name="robots" content="noindex,nofollow"> and a lang attribute.
     A theme layout that itself extends cms::account.layout gets Core's shell (no
     recursion). --}}
@php
    $__cmsAccountShellDepth = ($__cmsAccountShellDepth ?? 0) + 1;
    $accountUser ??= auth()->user();
    $accountNav ??= app('cms.account')->navigationItems($accountUser, request());
@endphp
@extends(app('cms.system_view')->accountLayout($__cmsAccountShellDepth > 1))
