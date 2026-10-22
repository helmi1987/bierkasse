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
     * With $withFlyerCode the newest active flyer code (the first one is created when there never was one);
     * plain URL when all flyer codes are deactivated.
     */
    public static function url(bool $withFlyerCode): string
    {
        if ($withFlyerCode) {
            QrCode::ensureFirst(QrCode::KIND_FLYER);
            $code = QrCode::newestActive(QrCode::KIND_FLYER);
            if ($code !== null) {
                return $code->url();
            }
        }

        return route('register');
    }
}
