{{-- Account sign-out form (CORE-40-ACCOUNT-SHELL-LOCALE-R1): CSRF-protected POST
     to the locale-aware logout route. --}}
<form method="POST" action="{{ cms_localized_route('cms.auth.logout') }}" style="margin-top:1rem;">
    @csrf
    <button type="submit" class="tn-btn tn-btn-ghost" style="width:100%;">{{ __('Sign out') }}</button>
</form>
