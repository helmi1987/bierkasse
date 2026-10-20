<?php

use App\Support\Money;
use Illuminate\Support\Carbon;

if (! function_exists('local_time')) {
    /**
     * Formats a unix timestamp or date in the application time zone.
     */
    function local_time(int|string|DateTimeInterface|null $value, string $format = 'd.m.Y H:i'): string
    {
        if ($value === null || $value === '' || $value === 0) {
            return '–';
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::createFromTimestamp((int) $value);

        return $date->setTimezone(config('app.timezone'))->format($format);
    }
}

if (! function_exists('chf')) {
    /** Rappen as "CHF 1.50". */
    function chf(int $rappen): string
    {
        return Money::format($rappen);
    }
}
