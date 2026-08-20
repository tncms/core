{{-- Default Theme — widget-area mega panel body (Phase 11C).
     Renders the pre-fetched widget_area() HTML (core widget renderer output).
     Nothing is queried here: the parent partial resolved the markup and only
     includes this when the area produced content. --}}
<div class="menu__mega-widgets">
    {!! $widgetHtml !!}
</div>
