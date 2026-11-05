<?php

namespace App\Services;

use App\Models\SsoProvider;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Sign in with Apple: the client secret is a short-lived ES256 JWT signed with
 * the private key (.p8) of the Apple developer account, created per token request.
 * No JWT library: openssl signs, the DER signature is converted to raw R||S (RFC 7518 3.4).
 */
final class AppleClientSecret
{
    public const AUDIENCE = 'https://appleid.apple.com';

    /** Lifetime of the client secret in seconds (Apple allows up to six months). */
    public const TTL = 300;

    /** Length of R and S for P-256 in bytes. */
    private const PART = 32;

    public static function forProvider(SsoProvider $provider, ?int $now = null): string
    {
        return self::create(
            (string) $provider->apple_team_id,
            (string) $provider->apple_key_id,
            (string) $provider->client_id,
            (string) $provider->client_secret,
            $now,
        );
    }

    public static function create(string $teamId, string $keyId, string $clientId, string $privateKeyPem, ?int $now = null): string
    {
        $key = self::loadKey($privateKeyPem);
        if ($key === null) {
            throw new RuntimeException(__('sso.apple_key_invalid'));
        }

        $now ??= time();
        $input = self::b64(self::json(['alg' => 'ES256', 'kid' => $keyId]))
            .'.'.self::b64(self::json([
                'iss' => $teamId,
                'iat' => $now,
                'exp' => $now + self::TTL,
                'aud' => self::AUDIENCE,
                'sub' => $clientId,
            ]));

        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException(__('sso.apple_key_invalid'));
        }

        return $input.'.'.self::b64(self::derToRaw((string) $der));
    }

    /**
     * Parses the PEM; only EC keys on P-256 (prime256v1) are accepted (ES256).
     */
    public static function loadKey(string $pem): ?OpenSSLAsymmetricKey
    {
        $pem = self::normalizePem($pem);
        if ($pem === '') {
            return null;
        }

        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return null;
        }

        return $key;
    }

    /**
     * Trims the pasted .p8 content and unifies line endings.
     */
    public static function normalizePem(string $pem): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $pem));
    }

    /**
     * DER ECDSA signature (SEQUENCE of two INTEGERs) to raw R||S, each left padded to 32 bytes.
     */
    public static function derToRaw(string $der): string
    {
        $pos = 0;
        if (self::byte($der, $pos++) !== 0x30) {
            throw new RuntimeException('Invalid DER signature.');
        }
        $length = self::byte($der, $pos++);
        if ($length === 0x81) {
            $length = self::byte($der, $pos++);
        } elseif ($length > 0x7F) {
            throw new RuntimeException('Invalid DER signature.');
        }
        if ($pos + $length !== strlen($der)) {
            throw new RuntimeException('Invalid DER signature.');
        }

        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            if (self::byte($der, $pos++) !== 0x02) {
                throw new RuntimeException('Invalid DER signature.');
            }
            $len = self::byte($der, $pos++);
            if ($len < 1 || $pos + $len > strlen($der)) {
                throw new RuntimeException('Invalid DER signature.');
            }
            $int = ltrim(substr($der, $pos, $len), "\x00");
            $pos += $len;
            if (strlen($int) > self::PART) {
                throw new RuntimeException('Invalid DER signature.');
            }
            $raw .= str_pad($int, self::PART, "\x00", STR_PAD_LEFT);
        }
        if ($pos !== strlen($der)) {
            throw new RuntimeException('Invalid DER signature.');
        }

        return $raw;
    }

    private static function byte(string $data, int $pos): int
    {
        if ($pos >= strlen($data)) {
            throw new RuntimeException('Invalid DER signature.');
        }

        return ord($data[$pos]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
