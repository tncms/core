{{-- Default Theme — SEO meta partial. Reads the current page SEO context
     resolved by TheNguyen\CMS\Services\SeoManager (see seo() helper). --}}
@php($seo = seo())
<title>{{ $seo->title() }}</title>
<meta name="description" content="{{ $seo->description() }}">
@if ($seo->keywords() !== '')
<meta name="keywords" content="{{ $seo->keywords() }}">
@endif
<meta name="robots" content="{{ $seo->robots() }}">
<link rel="canonical" href="{{ $seo->canonical() }}">

{{-- hreflang alternates (multi-language) --}}
@foreach ($seo->alternates() as $alt)
<link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['href'] }}">
@endforeach

{{-- Open Graph --}}
<meta property="og:title" content="{{ $seo->ogTitle() }}">
<meta property="og:description" content="{{ $seo->ogDescription() }}">
<meta property="og:type" content="{{ $seo->ogType() }}">
<meta property="og:url" content="{{ $seo->canonical() }}">
@if ($seo->ogImage())
<meta property="og:image" content="{{ $seo->ogImage() }}">
@endif

{{-- Twitter --}}
<meta name="twitter:card" content="{{ $seo->twitterCard() }}">
<meta name="twitter:title" content="{{ $seo->twitterTitle() }}">
<meta name="twitter:description" content="{{ $seo->twitterDescription() }}">
@if ($seo->twitterImage())
<meta name="twitter:image" content="{{ $seo->twitterImage() }}">
@endif
