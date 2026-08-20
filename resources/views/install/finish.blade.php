@extends('install.layout')

@section('title', 'Finished')

@section('content')
    @if ($already)
        <h1>Already installed</h1>
        <p class="lead">
            {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} is already installed. The installer is
            locked. Use the links below to reach your site.
        </p>
    @else
        <div class="alert alert--ok">
            🎉 Installation complete — {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} is ready.
        </div>
        <h1>You're all set</h1>
        <p class="lead">
            Migrations and seeders have run, your super-admin account is created, and the
            installer is now locked. Sign in to start building.
        </p>
    @endif

    <div class="url-box">
        <span class="label">Frontend</span>
        <a href="{{ $frontend_url }}">{{ $frontend_url }}</a>
    </div>
    <div class="url-box">
        <span class="label">Admin</span>
        <a href="{{ $admin_url }}">{{ $admin_url }}</a>
    </div>

    <div class="actions">
        <a class="btn btn--ghost" href="{{ $frontend_url }}">View site</a>
        <a class="btn btn--primary" href="{{ $admin_url }}">Go to admin →</a>
    </div>
@endsection
