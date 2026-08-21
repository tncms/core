@extends('install.layout')

@section('title', 'Review')

@section('content')
    <h1>Review &amp; install</h1>
    <p class="lead">
        Confirm the details below. When you install, TN CMS generates a unique
        application key, writes <code>.env</code>, sets up the database, and creates
        your admin account. Secrets are never shown.
    </p>

    @if ($error)
        <div class="alert alert--error">{{ $error }}</div>
    @endif

    @php($dsn = $db['username'].' @ '.$db['host'].':'.$db['port'].' / '.$db['database'])
    <ul class="checks">
        <li><span>Site name</span><span class="val">{{ $site['app_name'] }}</span></li>
        <li><span>Site URL</span><span class="val">{{ $site['app_url'] }}</span></li>
        <li><span>Timezone</span><span class="val">{{ $site['app_timezone'] }}</span></li>
        <li><span>Default language</span><span class="val">{{ $site['default_language'] }}</span></li>
        <li><span>Admin path</span><span class="val">/{{ $site['admin_path'] }}</span></li>
        <li><span>Database</span><span class="val">{{ $dsn }}</span></li>
        <li><span>Admin user</span><span class="val">{{ $admin['name'] }} &lt;{{ $admin['email'] }}&gt;</span></li>
    </ul>

    <form method="POST" action="{{ route('cms.install.run') }}">
        @csrf
        <div class="actions">
            <a class="btn btn--ghost" href="{{ route('cms.install.admin') }}">← Back</a>
            <button type="submit" class="btn btn--primary">Install TN CMS →</button>
        </div>
    </form>
@endsection
