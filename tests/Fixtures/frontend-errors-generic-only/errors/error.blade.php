{{-- Test fixture: a valid theme GENERIC error view with no specialized
     errors/{status} sibling, proving the responder uses the generic tier when
     no specialized template exists. --}}
<div id="generic-fixture-error">{{ $status }} :: {{ $title }}</div>
