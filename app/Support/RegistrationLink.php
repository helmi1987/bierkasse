<?php

namespace App\Support;

use App\Models\QrCode;

/**
 * Registration URL, optionally with a flyer code as ?flyer=… (registration without password).
 * The registration password itself is never put into a link.
 */
class RegistrationLink
{
    public const FLYER_QUERY = 'flyer';

    /**
     * With $withFlyerCode the newest active flyer code; plain URL when there is none (never created by an admin
     * yet, or all deactivated). Never creates a code (GET of a kassier).
     */
    public static function url(bool $withFlyerCode): string
    {
        if ($withFlyerCode) {
            $code = QrCode::newestActive(QrCode::KIND_FLYER);
            if ($code !== null) {
                return $code->url();
            }
        }

        return route('register');
    }
}
