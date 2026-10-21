@extends('layouts.app')

@section('title', __('maintenance.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('maintenance.title') }}</h1>
<p class="muted">{{ __('maintenance.intro') }}</p>

@if ($info['debug'] && $info['env'] === 'production')
    <div class="alert bad" role="alert">{{ __('maintenance.debug_warning') }}</div>
@endif

@if (session('maintenance_output'))
    <section class="card stack">
        <h2>{{ __('maintenance.output') }}</h2>
        <pre class="cmd-output">{{ session('maintenance_output') }}</pre>
    </section>
@endif

<section class="card stack">
    <h2>{{ __('maintenance.mode') }}</h2>
    <p class="hint">{{ __('maintenance.mode_hint') }}</p>
    <form method="post" action="{{ route('backend.maintenance.mode') }}" class="stack" data-confirm="{{ __('maintenance.mode_confirm') }}">
        @csrf
        @method('PUT')
        <label class="switch"><input type="checkbox" name="maintenance" value="1" @checked(old('maintenance', $maintenance))> <span>{{ __('maintenance.mode_switch') }}</span></label>
        <div class="field">
            <label for="maintenance_message">{{ __('maintenance.mode_message') }}</label>
            <textarea id="maintenance_message" name="maintenance_message" rows="2" maxlength="500">{{ old('maintenance_message', $maintenanceMessage) }}</textarea>
            <div class="hint">{{ __('maintenance.mode_message_hint') }}</div>
        </div>
        <div><button type="submit" class="btn primary">{{ __('maintenance.mode_save') }}</button></div>
    </form>
</section>

<section class="card stack">
    <h2>{{ __('maintenance.actions') }}</h2>

    <form method="post" action="{{ route('backend.maintenance.cache') }}" class="stack" data-confirm="{{ __('maintenance.cache_confirm') }}">
        @csrf
        <div><button type="submit" class="btn">{{ __('maintenance.cache') }}</button></div>
        <p class="hint">{{ __('maintenance.cache_hint') }}</p>
    </form>

    <form method="post" action="{{ route('backend.maintenance.limits') }}" class="stack" data-confirm="{{ __('maintenance.limits_confirm') }}">
        @csrf
        <div><button type="submit" class="btn">{{ __('maintenance.limits') }}</button></div>
        <p class="hint">{{ __('maintenance.limits_hint') }}</p>
    </form>

    <form method="post" action="{{ route('backend.maintenance.optimize') }}" class="stack" data-confirm="{{ __('maintenance.optimize_confirm') }}">
        @csrf
        <div><button type="submit" class="btn">{{ __('maintenance.optimize') }}</button></div>
        <p class="hint">{{ __('maintenance.optimize_hint') }}</p>
    </form>

    <form method="post" action="{{ route('backend.maintenance.migrate') }}" class="stack" data-confirm="{{ __('maintenance.migrate_confirm') }}">
        @csrf
        <div><button type="submit" @class(['btn', 'primary' => ! empty($pending)])>{{ __('maintenance.migrate') }}</button></div>
        <p class="hint">{{ __('maintenance.migrate_hint') }}</p>
        @if ($info['pending'] === null)
            <p class="muted">{{ __('maintenance.migrate_unknown') }}</p>
        @elseif ($pending === [])
            <p class="muted">{{ __('maintenance.migrate_none') }}</p>
        @else
            <div>
                <strong>{{ __('maintenance.migrate_pending') }}</strong>
                <pre class="cmd-output">{{ implode("\n", $pending) }}</pre>
            </div>
        @endif
    </form>
</section>

<section class="card stack">
    <h2>{{ __('maintenance.backup') }}</h2>
    <p class="hint">{{ __('maintenance.backup_hint') }}</p>
    <form method="post" action="{{ route('backend.maintenance.backup') }}" class="stack" data-confirm="{{ __('maintenance.backup_confirm') }}">
        @csrf
        <div><button type="submit" class="btn primary">{{ __('maintenance.backup_download') }}</button></div>
    </form>

    <h3>{{ __('maintenance.backup_stored') }}</h3>
    <p class="hint">{{ __('maintenance.backup_stored_hint') }}</p>
    @if ($backups === [])
        <p class="muted">{{ __('maintenance.backup_none') }}</p>
    @else
        <ul class="list">
            @foreach ($backups as $backup)
                @php($key = \Illuminate\Support\Str::beforeLast($backup['name'], '.sql.gz'))
                <li class="wrap-row">
                    <span>
                        <a href="{{ route('backend.maintenance.backup.file', $key) }}">{{ $backup['name'] }}</a>
                        <span class="muted">· {{ \Illuminate\Support\Number::fileSize($backup['size'], 1) }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['time'])->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                    </span>
                    <form method="post" action="{{ route('backend.maintenance.backup.delete', $key) }}" class="inline" data-confirm="{{ __('maintenance.backup_delete_confirm', ['name' => $backup['name']]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn small ghost">{{ __('maintenance.backup_delete') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section class="card stack">
    <h2>{{ __('maintenance.info') }}</h2>
    <dl class="info-list">
        <dt>{{ __('maintenance.version') }}</dt>
        <dd>
            @if ($info['git'])
                <code>{{ $info['git']['hash'] }}</code>
                @if ($info['git']['branch']) ({{ $info['git']['branch'] }}) @endif
                @if ($info['git']['date'])
                    · {{ \Illuminate\Support\Carbon::createFromTimestamp($info['git']['date'])->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}
                    @if ($info['git']['date_source'] === 'file') {{ __('maintenance.version_file_date') }} @endif
                @endif
            @else
                {{ __('maintenance.unknown') }}
            @endif
        </dd>
        <dt>{{ __('maintenance.php') }}</dt>
        <dd>{{ $info['php'] }}</dd>
        <dt>{{ __('maintenance.laravel') }}</dt>
        <dd>{{ $info['laravel'] }}</dd>
        <dt>{{ __('maintenance.database') }}</dt>
        <dd>
            {{ $info['db_driver'] }} ·
            @if ($info['db_ok'])
                <span class="pill">{{ __('maintenance.ok') }}</span>
            @else
                <span class="pill warn">{{ __('maintenance.error') }}</span> {{ __('maintenance.db_failed', ['error' => \Illuminate\Support\Str::limit((string) $info['db_error'], 200)]) }}
            @endif
        </dd>
        <dt>{{ __('maintenance.pending_count') }}</dt>
        <dd>{{ $info['pending'] === null ? __('maintenance.unknown') : count($info['pending']) }}</dd>
        <dt>{{ __('maintenance.drivers') }}</dt>
        <dd>{{ $info['cache'] }} / {{ $info['limiter'] }} / {{ $info['session'] }} / {{ $info['queue'] }} / {{ $info['mail'] }}</dd>
        <dt>{{ __('maintenance.storage') }}</dt>
        <dd>@if ($info['storage_writable']) {{ __('maintenance.yes') }} @else <span class="pill warn">{{ __('maintenance.no') }}</span> @endif</dd>
        <dt>{{ __('maintenance.bootstrap') }}</dt>
        <dd>@if ($info['bootstrap_writable']) {{ __('maintenance.yes') }} @else <span class="pill warn">{{ __('maintenance.no') }}</span> @endif</dd>
        <dt>{{ __('maintenance.env') }}</dt>
        <dd>{{ $info['env'] }}</dd>
        <dt>{{ __('maintenance.debug') }}</dt>
        <dd>@if ($info['debug']) <span @class(['pill', 'warn' => $info['env'] === 'production'])>{{ __('maintenance.yes') }}</span> @else {{ __('maintenance.no') }} @endif</dd>
        <dt>{{ __('maintenance.cached') }}</dt>
        <dd>{{ $info['config_cached'] ? __('maintenance.yes') : __('maintenance.no') }} / {{ $info['routes_cached'] ? __('maintenance.yes') : __('maintenance.no') }}</dd>
        <dt>{{ __('maintenance.disk_free') }}</dt>
        <dd>{{ $diskFree ?? __('maintenance.unknown') }}</dd>
    </dl>
</section>
@endsection
