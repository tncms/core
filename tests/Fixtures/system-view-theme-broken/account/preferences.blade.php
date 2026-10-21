{{-- Intentionally broken preferences override: throws during render. Proves the
     recursion-safe fallback keeps the preferences page reachable. --}}
@php throw new \RuntimeException('intentional theme preferences failure'); @endphp
<h1 data-marker="broken-theme-preferences">should-never-render</h1>
