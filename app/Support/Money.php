<?php

namespace App\Support;

/**
 * Money is stored as integer Rappen; shown as "CHF 1.50".
 */
class Money
{
    /** Accepted input: "12", "12.5", "12.50", "12,50" (optionally with leading minus). */
    public const INPUT_PATTERN = '/^-?\d{1,6}([.,]\d{1,2})?$/';

    public static function format(int $rappen): string
    {
        return 'CHF '.self::plain($rappen);
    }

    /** "1.50" / "-1.50" without currency. */
    public static function plain(int $rappen): string
    {
        $sign = $rappen < 0 ? '-' : '';
        $abs = abs($rappen);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Parses a francs input into Rappen; null when invalid. */
    public static function parse(mixed $input): ?int
    {
        $value = str_replace([' ', "'"], '', trim((string) $input));
        if (! preg_match(self::INPUT_PATTERN, $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim(str_replace(',', '.', $value), '-');
        [$francs, $cents] = array_pad(explode('.', $value, 2), 2, '0');
        $rappen = (int) $francs * 100 + (int) str_pad(substr($cents, 0, 2), 2, '0');

        return $negative ? -$rappen : $rappen;
    }
}
