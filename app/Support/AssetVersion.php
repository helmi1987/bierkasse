<?php

namespace App\Support;

/**
 * Version marker of the delivered CSS/JS (1.33.1): a short hash of the file dates of app.css, app.js and live.js,
 * the same dates that bust the asset URLs (?v=filemtime). The polling Livewire components compare it
 * (App\Livewire\Concerns\WatchesAssets); when it changes, public/js/live.js offers to reload the page instead of
 * morphing new markup into a document that still has the old stylesheet (seen after the 1.33.0 deploy).
 */
final class AssetVersion
{
    public const FILES = ['css/app.css', 'js/app.js', 'js/live.js'];

    public static function current(): string
    {
        $dates = [];
        foreach (self::FILES as $file) {
            $dates[] = @filemtime(public_path($file)) ?: 0;
        }

        return substr(hash('sha256', implode(':', $dates)), 0, 12);
    }
}
