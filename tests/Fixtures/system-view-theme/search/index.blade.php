{{-- Theme search override (theme::search.index). --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Theme Search</title></head>
<body>
<h1 data-marker="theme-search-override">theme-search-override</h1>
<p>{{ $page->keyword }}</p>
</body>
</html>
