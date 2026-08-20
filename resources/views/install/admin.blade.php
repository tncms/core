@extends('install.layout')

@section('title', 'Admin account')

@section('content')
    <h1>Create the super-admin</h1>
    <p class="lead">
        This account has full access to the admin panel. You can add more users later.
        On submit, {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} runs migrations, seeds the
        defaults, and locks the installer.
    </p>

    @if (session('install_error'))
        <div class="alert alert--error">{{ session('install_error') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert--error">Please correct the highlighted fields.</div>
    @endif

    <form method="POST" action="{{ route('cms.install.admin.store') }}">
        @csrf

        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required>
            @error('name')<div class="err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required>
            @error('email')<div class="err">{{ $message }}</div>@enderror
        </div>

        <div class="row">
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" required>
                <div class="hint">At least 8 characters.</div>
                @error('password')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
            </div>
        </div>

        <div class="actions">
            <a class="btn btn--ghost" href="{{ route('cms.install.database') }}">← Back</a>
            <button type="submit" class="btn btn--primary">Install {{ \TheNguyen\CMS\Support\CmsInfo::BRAND }} →</button>
        </div>
    </form>
@endsection
