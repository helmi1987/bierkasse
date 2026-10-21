{{--
    Generic notice: title, paragraphs, key/value table, button, closing paragraphs.
    Data: title, lines (list), rows (label => value), textBlock (free text, escaped, line breaks kept),
    buttonUrl, buttonLabel, outro (list).
--}}
@extends('mail.layout')

@section('content')
@php $font = '-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif'; @endphp
@if (! empty($title))
<h1 style="margin:0 0 16px 0; font-family:{{ $font }}; font-size:22px; line-height:28px; font-weight:700; color:#1f1a14;">{{ $title }}</h1>
@endif
@foreach ($lines ?? [] as $line)
<p style="margin:0 0 14px 0; color:#1f1a14;">{{ $line }}</p>
@endforeach
@if (! empty($rows))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:6px 0 20px 0; border-collapse:separate; border:1px solid #eadfcb; border-radius:10px;">
@foreach ($rows as $label => $value)
    <tr>
        <td bgcolor="#fbf3e2" style="background-color:#fbf3e2; padding:10px 12px; width:38%; vertical-align:top; font-family:{{ $font }}; font-size:14px; line-height:20px; color:#6b6152; font-weight:600;{{ $loop->last ? '' : ' border-bottom:1px solid #eadfcb;' }}">{{ $label }}</td>
        <td bgcolor="#ffffff" style="background-color:#ffffff; padding:10px 12px; vertical-align:top; font-family:{{ $font }}; font-size:15px; line-height:20px; color:#1f1a14;{{ $loop->last ? '' : ' border-bottom:1px solid #eadfcb;' }}">{{ $value }}</td>
    </tr>
@endforeach
</table>
@endif
@if (($textBlock ?? '') !== '')
<div style="margin:0 0 20px 0; padding:12px 14px; border-left:3px solid #b45309; background-color:#fbf3e2; font-size:15px; line-height:22px; color:#1f1a14; word-break:break-word;">{!! nl2br(e($textBlock), false) !!}</div>
@endif
@if (! empty($buttonUrl))
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px 0;">
    <tr>
        <td bgcolor="#b45309" style="background-color:#b45309; border-radius:10px;">
            <a href="{{ $buttonUrl }}" style="display:inline-block; padding:12px 22px; font-family:{{ $font }}; font-size:16px; line-height:20px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px; background-color:#b45309;">{{ $buttonLabel }}</a>
        </td>
    </tr>
</table>
@endif
@foreach ($outro ?? [] as $line)
<p style="margin:0 0 14px 0; color:#1f1a14;">{{ $line }}</p>
@endforeach
@if (! empty($buttonUrl))
<p style="margin:16px 0 0 0; font-size:13px; line-height:19px; color:#6b6152;">{{ __('mail.button_fallback') }}<br>
<a href="{{ $buttonUrl }}" style="color:#b45309; text-decoration:underline; word-break:break-all;">{{ $buttonUrl }}</a></p>
@endif
@endsection
