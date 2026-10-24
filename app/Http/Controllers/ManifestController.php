<?php

namespace App\Http\Controllers;

use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;

/**
 * PWA manifest; name from the settings (fallback APP_NAME), all URLs relative.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = config('app.installed') ? AppSettings::appName() : (string) config('app.name', 'Bierkasse');

        return response()->json([
            'name' => $name,
            'short_name' => $name,
            'description' => __('site.manifest_description'),
            'lang' => 'de-CH',
            'start_url' => '/konto',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#fff8eb',
            'theme_color' => '#d97706',
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/logo.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
