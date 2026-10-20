<?php

namespace App\Support;

use App\Models\User;

/**
 * RFC 6238 time-based one-time passwords (SHA1, 6 digits, 30 s),
 * compatible with Google Authenticator, Aegis, 1Password, Bitwarden etc.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Accepted clock drift in time steps (±30 s). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Returns the matching time step, or null. Steps up to and including
     * $lastStep are rejected so a code can only be used once.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $now = self::currentStep($time);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $now + $i;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    /**
     * Checks a TOTP code or a recovery code for the user and consumes it.
     */
    public static function verifyUser(User $user, string $input): bool
    {
        if (! $user->hasTotp()) {
            return false;
        }

        $step = self::verify((string) $user->totp_secret, $input, $user->totp_last_step);
        if ($step !== null) {
            $user->forceFill(['totp_last_step' => $step])->save();

            return true;
        }

        return self::useRecoveryCode($user, $input);
    }

    /**
     * @return list<string> Plain codes (shown once); only hashes are stored.
     */
    public static function newRecoveryCodes(User $user, int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtolower(self::base32Encode(random_bytes(7)));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5, 5);
        }

        $user->forceFill(['totp_recovery_codes' => array_map([self::class, 'hashRecovery'], $codes)])->save();

        return $codes;
    }

    public static function remainingRecoveryCodes(User $user): int
    {
        return count((array) $user->totp_recovery_codes);
    }

    private static function useRecoveryCode(User $user, string $input): bool
    {
        $input = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        if (strlen($input) !== 10) {
            return false;
        }

        $hash = self::hashRecovery($input);
        $codes = (array) $user->totp_recovery_codes;
        foreach ($codes as $i => $stored) {
            if (hash_equals((string) $stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['totp_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private static function hashRecovery(string $code): string
    {
        return hash_hmac('sha256', strtolower(str_replace('-', '', $code)), (string) config('app.key'));
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[\s=]/', '', $data) ?? '');
        $bits = '';
        foreach (str_split($data) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
