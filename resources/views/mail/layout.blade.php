{{--
    Shared HTML mail layout. Inline CSS and tables only: many mail clients drop <style> blocks.
    Colors from public/css/app.css (light theme); every cell sets its own background so
    clients that force a dark mode never end up with light text on a transparent background.
--}}
@php
    $appName = $appName ?? \App\Support\AppSettings::appName();
    $appUrl = $appUrl ?? url('/');
    // Logo as inline CID attachment (SVG is not supported by most clients). Optional.
    $logoSrc = null;
    $logoPath = public_path('icon-192.png');
    if (isset($message) && is_file($logoPath)) {
        try {
            $logoSrc = $message->embed($logoPath);
        } catch (\Throwable) {
            $logoSrc = null;
        }
    }
    $font = '-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif';
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $title ?? $appName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#fdf8ef; color:#1f1a14; font-family:{{ $font }};">
@if (! empty($preheader))
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $preheader }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fdf8ef" style="background-color:#fdf8ef; width:100%;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
    {{-- Header: logo + app name --}}
    <tr>
        <td bgcolor="#fdf8ef" style="padding:0 4px 16px 4px; background-color:#fdf8ef;">
            <a href="{{ $appUrl }}" style="text-decoration:none; color:#1f1a14;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        @if ($logoSrc)
                        <td style="padding-right:12px; vertical-align:middle;">
                            <img src="{{ $logoSrc }}" width="44" height="44" alt="{{ $appName }} Logo" style="display:block; width:44px; height:44px; border:0; border-radius:10px;">
                        </td>
                        @endif
                        <td style="vertical-align:middle; font-family:{{ $font }}; font-size:22px; line-height:28px; font-weight:700; color:#1f1a14;">{{ $appName }}</td>
                    </tr>
                </table>
            </a>
        </td>
    </tr>
    {{-- Card --}}
    <tr>
        <td bgcolor="#ffffff" style="background-color:#ffffff; border:1px solid #eadfcb; border-top:4px solid #d97706; border-radius:16px; padding:28px 28px 24px 28px; font-family:{{ $font }}; font-size:16px; line-height:24px; color:#1f1a14;">
            @yield('content')
        </td>
    </tr>
    {{-- Footer --}}
    <tr>
        <td bgcolor="#fdf8ef" style="background-color:#fdf8ef; padding:16px 4px 0 4px; font-family:{{ $font }}; font-size:13px; line-height:20px; color:#6b6152; text-align:center;">
            {{ __('mail.automatic', ['app' => $appName]) }}<br>
            <a href="{{ $appUrl }}" style="color:#b45309; text-decoration:underline;">{{ preg_replace('#^https?://#', '', rtrim($appUrl, '/')) }}</a>
        </td>
    </tr>
</table>
</td>
</tr>
</table>
</body>
</html>
