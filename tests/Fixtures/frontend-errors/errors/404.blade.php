{{-- Test fixture: a deliberately broken theme 404 view (throws on render) to
     prove the FrontendErrorResponder degrades to the Core fallback safely. --}}
@php(throw new \RuntimeException('theme 404 render boom'))
