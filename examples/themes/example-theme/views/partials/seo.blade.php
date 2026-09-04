{{-- SEO meta comes from Core's SeoManager — themes render, never invent it. --}}
@php($seo = seo())
<title>{{ $seo->title() }}</title>
<meta name="description" content="{{ $seo->description() }}">
<meta name="robots" content="{{ $seo->robots() }}">
<link rel="canonical" href="{{ $seo->canonical() }}">
@foreach ($seo->alternates() as $alt)
<link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['href'] }}">
@endforeach
