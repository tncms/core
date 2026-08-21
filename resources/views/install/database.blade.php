@extends('install.layout')

@section('title', 'Configure')

@section('content')
    <h1>Site &amp; database configuration</h1>
    <p class="lead">
        Enter your database connection and a few site basics. We only test the
        connection now — nothing is written to <code>.env</code> until you confirm on
        the review step. Your password is never shown back.
    </p>

    @if (session('db_error'))
        <div class="alert alert--error">{{ session('db_error') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert--error">
            Please correct the highlighted fields.
        </div>
    @endif

    <form method="POST" action="{{ route('cms.install.database.store') }}">
        @csrf

        <div class="row">
            <div class="field">
                <label for="app_name">Site name</label>
                <input type="text" id="app_name" name="app_name" value="{{ old('app_name', $defaults['app_name']) }}" required>
                @error('app_name')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="app_url">Site URL</label>
                <input type="url" id="app_url" name="app_url" value="{{ old('app_url', $defaults['app_url']) }}" required>
                @error('app_url')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="row">
            <div class="field">
                <label for="app_timezone">Timezone</label>
                <input type="text" id="app_timezone" name="app_timezone" value="{{ old('app_timezone', $defaults['app_timezone']) }}" required>
                @error('app_timezone')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="default_language">Default language</label>
                <select id="default_language" name="default_language" required>
                    @foreach (['vi' => 'Tiếng Việt (vi)', 'en' => 'English (en)'] as $code => $name)
                        <option value="{{ $code }}" @selected(old('default_language', $defaults['default_language']) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('default_language')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="field">
            <label for="admin_path">Admin URL path</label>
            <input type="text" id="admin_path" name="admin_path" value="{{ old('admin_path', $defaults['admin_path']) }}" required>
            <div class="hint">Lowercase letters, numbers and dashes only. Your admin will live at <code>/&lt;path&gt;</code>.</div>
            @error('admin_path')<div class="err">{{ $message }}</div>@enderror
        </div>

        <div class="row">
            <div class="field">
                <label for="db_host">Database host</label>
                <input type="text" id="db_host" name="db_host" value="{{ old('db_host', $defaults['db_host']) }}" required>
                @error('db_host')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="db_port">Database port</label>
                <input type="number" id="db_port" name="db_port" value="{{ old('db_port', $defaults['db_port']) }}" required>
                @error('db_port')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="field">
            <label for="db_database">Database name</label>
            <input type="text" id="db_database" name="db_database" value="{{ old('db_database', $defaults['db_database']) }}" required>
            @error('db_database')<div class="err">{{ $message }}</div>@enderror
        </div>

        <div class="row">
            <div class="field">
                <label for="db_username">Database username</label>
                <input type="text" id="db_username" name="db_username" value="{{ old('db_username', $defaults['db_username']) }}" required>
                @error('db_username')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="db_password">Database password</label>
                <input type="password" id="db_password" name="db_password" autocomplete="new-password" value="">
                <div class="hint">Leave blank if your database has no password.</div>
                @error('db_password')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="actions">
            <a class="btn btn--ghost" href="{{ route('cms.install.requirements') }}">← Back</a>
            <button type="submit" class="btn btn--primary">Test &amp; continue →</button>
        </div>
    </form>
@endsection
