<?php

namespace App\Support;

/**
 * Registration URL, optionally with the flyer code as ?flyer=… (registration without password).
 * The registration password itself is never put into a link.
 */
class RegistrationLink
{
    public const FLYER_QUERY = 'flyer';

    public static function url(bool $withFlyerCode): string
    {
        $url = route('register');
        if ($withFlyerCode) {
            $url .= '?'.http_build_query([self::FLYER_QUERY => AppSettings::flyerCode()]);
        }

        return $url;
    }
}
