@php $appName = $appName ?? \App\Support\AppSettings::appName(); @endphp
{!! $appName !!}
{!! str_repeat('=', mb_strlen($appName)) !!}

@if (! empty($title))
{!! $title !!}

@endif
@foreach ($lines ?? [] as $line)
{!! $line !!}

@endforeach
@foreach ($rows ?? [] as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
@if (! empty($rows))

@endif
@if (! empty($buttonUrl))
{!! $buttonLabel !!}:
{!! $buttonUrl !!}

@endif
@foreach ($outro ?? [] as $line)
{!! $line !!}

@endforeach
--
{!! __('mail.automatic', ['app' => $appName]) !!}
{!! $appUrl ?? url('/') !!}
