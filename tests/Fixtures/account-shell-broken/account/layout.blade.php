{{-- Fixture: a theme account layout that fails at render time. --}}
<div>should-never-render-broken-shell</div>
@php
    throw new \RuntimeException('broken-theme-account-layout');
@endphp
