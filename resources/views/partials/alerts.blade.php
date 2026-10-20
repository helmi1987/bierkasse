@if (session('status'))
    <div class="alert ok" role="status">{{ session('status') }}</div>
@endif
@if (session('warning'))
    <div class="alert warn" role="alert">{{ session('warning') }}</div>
@endif
@if (session('error'))
    <div class="alert bad" role="alert">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="alert bad" role="alert">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
