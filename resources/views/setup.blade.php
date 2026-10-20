@extends('layouts.app')

@section('title', __('setup.title'))

@section('content')
<h1>{{ __('setup.title') }}</h1>
<p class="muted">{{ __('setup.intro') }}</p>

<form method="post" action="{{ route('setup.store') }}" class="card stack" autocomplete="off">
    @csrf
    <h2>{{ __('setup.token_heading') }}</h2>
    <div class="field">
        <label for="token">{{ __('setup.token') }}</label>
        <input type="text" id="token" name="token" required>
        <div class="hint">{{ __('setup.token_hint', ['path' => $tokenPath]) }}</div>
    </div>
    <div class="field">
        <label for="app_url">{{ __('setup.app_url') }}</label>
        <input type="url" id="app_url" name="app_url" value="{{ old('app_url', $appUrl) }}" required>
    </div>

    <h2>{{ __('setup.db_heading') }}</h2>
    <div class="grid-2">
        <div class="field">
            <label for="driver">{{ __('setup.driver') }}</label>
            <select id="driver" name="driver">
                @foreach ($drivers as $key => $label)
                    <option value="{{ $key }}" @selected(old('driver', $db['driver']) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="database">{{ __('setup.database') }}</label>
            <input type="text" id="database" name="database" value="{{ old('database', $db['database']) }}" required>
            <div class="hint">{{ __('setup.database_hint', ['path' => $sqliteDefault]) }}</div>
        </div>
        <div class="field">
            <label for="host">{{ __('setup.host') }}</label>
            <input type="text" id="host" name="host" value="{{ old('host', $db['host'] ?: 'localhost') }}">
        </div>
        <div class="field">
            <label for="port">{{ __('setup.port') }}</label>
            <input type="number" id="port" name="port" value="{{ old('port', $db['port'] ?: 3306) }}">
            <div class="hint">{{ __('setup.port_hint') }}</div>
        </div>
        <div class="field">
            <label for="username">{{ __('setup.username') }}</label>
            <input type="text" id="username" name="username" value="{{ old('username', $db['username']) }}">
        </div>
        <div class="field">
            <label for="password">{{ __('setup.password') }}</label>
            <input type="password" id="password" name="password" autocomplete="new-password">
        </div>
    </div>
    <p class="hint">{{ __('setup.server_fields_hint') }}</p>

    <h2>{{ __('setup.admin_heading') }}</h2>
    <div class="grid-2">
        <div class="field">
            <label for="admin_username">{{ __('setup.admin_username') }}</label>
            <input type="text" id="admin_username" name="admin_username" value="{{ old('admin_username', 'admin') }}" pattern="[a-z0-9._\-]{3,32}" required>
            <div class="hint">{{ __('setup.username_hint') }}</div>
        </div>
        <div class="field">
            <label for="admin_email">{{ __('setup.admin_email') }}</label>
            <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}">
        </div>
        <div class="field">
            <label for="admin_first_name">{{ __('setup.admin_first_name') }}</label>
            <input type="text" id="admin_first_name" name="admin_first_name" value="{{ old('admin_first_name') }}">
        </div>
        <div class="field">
            <label for="admin_last_name">{{ __('setup.admin_last_name') }}</label>
            <input type="text" id="admin_last_name" name="admin_last_name" value="{{ old('admin_last_name') }}">
        </div>
        <div class="field">
            <label for="admin_password">{{ __('setup.admin_password') }}</label>
            <input type="password" id="admin_password" name="admin_password" autocomplete="new-password" minlength="12" required>
        </div>
        <div class="field">
            <label for="admin_password_confirmation">{{ __('setup.admin_password_confirmation') }}</label>
            <input type="password" id="admin_password_confirmation" name="admin_password_confirmation" autocomplete="new-password" required>
        </div>
    </div>
    <p class="hint">{{ __('setup.totp_hint') }}</p>

    <button class="btn primary big" type="submit">{{ __('setup.submit') }}</button>
</form>
@endsection
