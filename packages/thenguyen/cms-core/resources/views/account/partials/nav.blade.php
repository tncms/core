{{-- Account navigation (CORE-40-ACCOUNT-SHELL-LOCALE-R1): the items from
     app('cms.account')->navigationItems() — Core and plugin items, already
     permission-filtered, priority-sorted, locale-aware and active-marked — plus the
     cms.account.navigation render area. Expects $accountNav. --}}
<nav aria-label="{{ __('Account navigation') }}">
    <ul class="tn-acc-nav">
        @foreach ($accountNav as $item)
            <li>
                <a href="{{ $item->url }}" @if ($item->active) aria-current="page" @endif>
                    <span>{{ $item->label }}</span>
                    @if ($item->badge !== null)<span class="tn-acc-badge">{{ $item->badge }}</span>@endif
                </a>
            </li>
        @endforeach
    </ul>
    {!! render_hook('cms.account.navigation') !!}
</nav>
