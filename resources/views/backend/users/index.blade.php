@extends('layouts.app')

@section('title', __('users.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('users.title') }}</h1>
<nav class="tabs">
    <a href="{{ route('backend.users.index') }}" @class(['active' => $status === 'active'])>{{ __('users.active') }}</a>
    <a href="{{ route('backend.users.index', ['status' => 'left']) }}" @class(['active' => $status === 'left'])>{{ __('users.left') }}</a>
</nav>

<section class="card">
    @if ($users->isEmpty())
        <p class="muted">{{ __('users.none') }}</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('users.name') }}</th>
                        <th>{{ __('users.role') }}</th>
                        <th class="num">{{ __('users.limit') }}</th>
                        <th class="num">{{ __('users.balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        @php($balance = $balances[$user->id] ?? 0)
                        <tr>
                            <td><a href="{{ route('backend.users.show', $user) }}"><strong>{{ $user->displayName() }}</strong></a>@if ($user->isAdminOnly()) <span class="pill">{{ __('account.admin_only') }}</span>@endif<br><span class="muted">{{ $user->username }}</span></td>
                            <td>{{ __('users.roles.'.$user->role) }}</td>
                            @if ($user->isAdminOnly())
                                <td class="num muted">–</td>
                                <td class="num muted">–</td>
                            @else
                                <td class="num">{{ $user->overdraft_limit === null ? __('users.limit_default', ['limit' => chf(\App\Support\AppSettings::defaultLimit())]) : chf($user->overdraft_limit) }}</td>
                                <td @class(['num', 'amount', 'neg' => $balance < 0, 'pos' => $balance > 0])>{{ chf($balance) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
