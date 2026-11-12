{{-- Account sidebar (CORE-40-ACCOUNT-SHELL-LOCALE-R1): the sidebar render areas,
     the signed-in user, the permission-filtered navigation and the sign-out form.
     Shared by Core's shell and any theme::account.layout, so a theme never has to
     re-implement navigation, hooks or CSRF. Expects $accountUser and $accountNav
     (provided by cms::account.layout). --}}
{!! render_hook('cms.account.sidebar.before') !!}

<div class="tn-acc-user">
    <strong>{{ $accountUser?->name }}</strong>
    <span>{{ $accountUser?->email }}</span>
</div>

@include('cms::account.partials.nav')

@include('cms::account.partials.sign-out')

{!! render_hook('cms.account.sidebar.after') !!}
