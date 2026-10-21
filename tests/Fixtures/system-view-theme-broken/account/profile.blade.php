{{-- Intentionally broken profile override: throws during render. Proves the
     resolver's recursion-safe fallback keeps the profile page reachable (renders
     the Core fallback) and never leaves it offline. --}}
@php throw new \RuntimeException('intentional theme profile failure'); @endphp
<h1 data-marker="broken-theme-profile">should-never-render</h1>
