<?php

namespace App\Support;

/**
 * Registration URL, optionally with the registration password as ?code=… (prefills the form).
 */
class RegistrationLink
{
    public static function url(bool $withCode): string
    {
        $url = route('register');
        $code = AppSettings::registrationPassword();
        if ($withCode && $code !== '') {
            $url .= '?'.http_build_query(['code' => $code]);
        }

        return $url;
    }
}
