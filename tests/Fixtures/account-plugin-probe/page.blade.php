{{-- Fixture plugin account page: extends the stable target exactly like Toplist does
     (`@extends('cms::account.layout')`), unchanged. --}}
@extends('cms::account.layout')

@section('title', 'Probe articles')

@section('account_content')
    <section data-marker="probe-plugin-content">probe-plugin-content</section>
@endsection
