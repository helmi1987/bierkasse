{{-- Brand-styled SSO button. Params: $provider (SsoProvider), $href, $label, optional $big. Marks are simplified, self-drawn shapes. --}}
@php($brand = $provider->brand())
<a @class(['btn', 'sso', 'sso-'.$brand, 'big' => $big ?? false]) href="{{ $href }}">
    <svg class="sso-mark" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
        @switch($brand)
            @case('google')
                <g fill="none" stroke-width="4">
                    <path d="M5.447 7.411A8 8 0 0 1 18.128 6.858" stroke="#EA4335"/>
                    <path d="M6.343 17.657A8 8 0 0 1 5.447 7.411" stroke="#FBBC05"/>
                    <path d="M17.657 17.657A8 8 0 0 1 6.343 17.657" stroke="#34A853"/>
                    <path d="M20 12A8 8 0 0 1 17.657 17.657" stroke="#4285F4"/>
                </g>
                <rect x="12" y="10" width="10" height="4" fill="#4285F4"/>
                @break
            @case('microsoft')
                <rect x="2" y="2" width="9.5" height="9.5" fill="#F25022"/>
                <rect x="12.5" y="2" width="9.5" height="9.5" fill="#7FBA00"/>
                <rect x="2" y="12.5" width="9.5" height="9.5" fill="#00A4EF"/>
                <rect x="12.5" y="12.5" width="9.5" height="9.5" fill="#FFB900"/>
                @break
            @case('github')
                <path fill="currentColor" d="M12 1.5a10.5 10.5 0 1 0 0 21 10.5 10.5 0 0 0 0-21zm-5.6 5 3 1.9a8 8 0 0 1 5.2 0l3-1.9.4 3.6a5.6 5.6 0 0 1 1 3.2c0 3-2.2 4.4-4.4 4.8.5.5.7 1.1.7 1.8V21a8.6 8.6 0 0 1-6.6 0v-2.1c-1.8.3-2.6-.6-3-1.5-.3-.6-.7-1-1.2-1.2.6-.2 1.3.1 1.8.9.5.8 1.2 1 2.4.6.1-.6.4-1.1.7-1.4-2.2-.4-4.4-1.8-4.4-4.8a5.6 5.6 0 0 1 1-3.2z"/>
                @break
            @case('facebook')
                <circle cx="12" cy="12" r="11" fill="#fff"/>
                <path fill="#1877F2" d="M13.4 23v-8.4h2.8l.4-3.3h-3.2V9.2c0-.9.3-1.6 1.6-1.6h1.7V4.7c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.4H7.2v3.3H10V23z"/>
                @break
            @default
                <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="8" cy="15" r="4.5"/>
                    <path d="M11.2 11.8 20 3M16.5 6.5l2.5 2.5M14 9l2 2"/>
                </g>
        @endswitch
    </svg>
    <span>{{ $label }}</span>
</a>
