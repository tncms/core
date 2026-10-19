{{-- Intentionally broken theme override: throws during render. Proves the
     resolver's recursion-safe fallback keeps login reachable (renders the Core
     fallback) and never leaves the page offline. --}}
@php throw new \RuntimeException('intentional theme login failure'); @endphp
<h1 data-marker="broken-theme-login">should-never-render</h1>
