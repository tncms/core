{{-- Intentionally broken security override: throws during render. Proves the
     recursion-safe fallback keeps the security page reachable. --}}
@php throw new \RuntimeException('intentional theme security failure'); @endphp
<h1 data-marker="broken-theme-security">should-never-render</h1>
