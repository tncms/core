{{-- Fixture: a theme account layout that includes a partial the theme does not ship. --}}
<div>should-never-render-missing-partial-shell</div>
@include('theme::partials.account-chrome-that-does-not-exist')
@yield('account_content')
