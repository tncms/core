{{-- Account flash + error summary (CORE-40-ACCOUNT-SHELL-LOCALE-R1). --}}
@if (session('status'))
    <div class="tn-alert tn-alert-status" role="status">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <ul class="tn-errors" role="alert">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
@endif
