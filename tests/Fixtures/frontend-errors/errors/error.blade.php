{{-- Test fixture: a deliberately broken theme generic error view (throws on
     render) so both theme tiers fail and the Core fallback must take over. --}}
@php(throw new \RuntimeException('theme generic error render boom'))
