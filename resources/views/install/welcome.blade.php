@extends('install.layout')

@section('title', 'Welcome')

@section('content')
    <h1>Welcome to {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }}</h1>
    <p class="lead">
        This short setup will check your server, configure your database, create your
        administrator account, and get your site running. It takes about a minute.
    </p>

    <ul class="checks">
        <li><span>Check server requirements</span><span class="val">Step 2</span></li>
        <li><span>Configure database &amp; site</span><span class="val">Step 3</span></li>
        <li><span>Create the super-admin account</span><span class="val">Step 4</span></li>
        <li><span>Run migrations &amp; finish</span><span class="val">Step 5</span></li>
    </ul>

    <div class="actions">
        <span></span>
        <a class="btn btn--primary" href="{{ route('cms.install.requirements') }}">Get started →</a>
    </div>
@endsection
