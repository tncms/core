{{-- Custom page template "landing" — declared in theme.json "page_templates".
     A Page stores only the stable id "landing"; Core resolves it to this view
     through the active theme's validated allowlist (never a raw Blade path).
     The template still runs inside the normal shell: master layout, Core
     ViewModel data ($title/$body/...), escaping via cms_html(). --}}
@extends('theme::layouts.master')

@section('content')
    <article class="entry entry--landing">
        <header class="landing-hero">
            {{-- The landing hero owns its heading; honour the visibility flag. --}}
            @if ($showPageTitle ?? true)
                <h1 class="entry-title">{{ $title }}</h1>
            @endif
        </header>
        <div class="entry-content">
            {!! cms_html($body ?? '') !!}
        </div>
    </article>
@endsection
