{{-- Intentionally broken sessions override: throws during render. Proves the
     resolver's recursion-safe fallback keeps the sessions page reachable (renders
     the Core fallback) and never leaves it offline. --}}
@php throw new \RuntimeException('intentional theme sessions failure'); @endphp
<h1 data-marker="broken-theme-sessions">should-never-render</h1>
