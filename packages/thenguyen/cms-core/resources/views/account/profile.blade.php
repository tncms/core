@extends('cms::account.layout')

@section('title', __('Profile'))

@section('account_content')
    @php($user = auth()->user())
    <div class="tn-acc-card">
        <h1>{{ __('Profile') }}</h1>
        <form method="POST" action="{{ route('cms.account.profile.update') }}">
            @csrf
            <div class="tn-field">
                <label for="name">{{ __('Name') }}</label>
                <input id="name" type="text" name="name" value="{{ old('name', $user?->name) }}" required autocomplete="name">
            </div>
            <div class="tn-field">
                <label for="username">{{ __('Username') }}</label>
                <input id="username" type="text" name="username" value="{{ old('username', $user?->username) }}" autocomplete="username">
                <div class="tn-hint">{{ __('Letters, numbers, dashes and underscores. Up to 50 characters.') }}</div>
            </div>
            <div class="tn-field">
                <label for="phone">{{ __('Phone') }}</label>
                <input id="phone" type="text" name="phone" value="{{ old('phone', $user?->phone) }}" autocomplete="tel">
            </div>
            <div class="tn-field">
                <label for="avatar">{{ __('Avatar URL') }}</label>
                <input id="avatar" type="url" name="avatar" value="{{ old('avatar', $user?->avatar) }}" placeholder="https://…">
            </div>
            <div class="tn-field">
                <label for="bio">{{ __('Bio') }}</label>
                <textarea id="bio" name="bio" rows="4" maxlength="1000">{{ old('bio', $user?->bio) }}</textarea>
            </div>
            <button type="submit" class="tn-btn">{{ __('Save profile') }}</button>
        </form>
    </div>

    {!! render_hook('cms.account.profile.after') !!}
@endsection
