{{-- PWA install hint; shown by public/js/app.js only when the app can be installed and is not running installed. --}}
<aside class="install-hint no-print" data-install-hint data-live-keep="install" hidden>
    <img src="{{ asset('icon-192.png') }}" alt="" width="44" height="44">
    <div class="install-text">
        <strong>{{ __('site.install.title', ['app' => $appName]) }}</strong>
        <span data-install-variant="prompt" hidden>{{ __('site.install.prompt') }}</span>
        <span data-install-variant="ios" hidden>{!! __('site.install.ios', ['share' => '<svg class="icon inline-icon" viewBox="0 0 24 24" aria-label="'.e(__('site.install.share')).'"><path d="M12 3l4 4-1.4 1.4L13 6.8V15h-2V6.8L9.4 8.4 8 7l4-4Zm-7 9h3v2H7v6h10v-6h-1v-2h3v10H5V12Z"/></svg>']) !!}</span>
        <span data-install-variant="other" hidden>{{ __('site.install.other') }}</span>
    </div>
    <div class="install-actions">
        <button type="button" class="btn primary small" data-install-button hidden>{{ __('site.install.button') }}</button>
        <button type="button" class="btn ghost small" data-install-dismiss aria-label="{{ __('site.install.dismiss') }}" title="{{ __('site.install.dismiss') }}">✕</button>
    </div>
</aside>
