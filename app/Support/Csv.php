<?php

namespace App\Support;

/**
 * Small helpers for the CSV exports (audit log, backend statistics).
 */
final class Csv
{
    /** Against formula injection in spreadsheet programs: a leading =, +, -, @, tab or CR is quoted with '. */
    public static function safe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
