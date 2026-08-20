{{-- Default Theme — content sidebar.

    Layout-only container. It renders two optional kinds of content:

      1. $sidebar — an iterable of already-safe HTML strings passed by a
         controller / ViewModel (the original Phase 8A contract).
      2. A widget region — when the caller passes $region (e.g. 'post.sidebar'),
         that region's assigned widgets are rendered through the Core widget
         renderer via the widget_area() helper.

    No database queries live here: widget_area() is the Core helper and is
    null-safe — it returns '' when the widget system is unavailable, the region
    is unknown/empty/inactive, or anything throws — so the sidebar can never
    break a page. When nothing is provided it still renders an empty but valid
    <aside> so the content-layout grid stays intact. --}}
@php($region = $region ?? null)
@php($widgetHtml = is_string($region) && $region !== '' ? widget_area($region) : '')
<aside class="tn-content-sidebar" aria-label="{{ theme_trans('Sidebar') }}">
    @isset($sidebar)
        @foreach ((array) $sidebar as $block)
            <div class="tn-sidebar-block">{!! $block !!}</div>
        @endforeach
    @endisset

    @if ($widgetHtml !== '')
        <div class="tn-sidebar-widgets">{!! $widgetHtml !!}</div>
    @endif
</aside>
