<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SSRF protection for server-side requests to URLs supplied by users
 * (personal SSO providers): https only, the host must resolve to public
 * addresses only, the resolved address is pinned for the connection
 * (no DNS rebinding), redirects are not followed, timeouts and a
 * response size limit apply.
 */
class OutboundUrl
{
    /** Maximum response body size in bytes. */
    public const MAX_BYTES = 1048576;

    /** @var (Closure(string): list<string>)|null Test hook: host => IP addresses. */
    private static ?Closure $resolver = null;

    /**
     * Blocked IPv4 ranges (private, loopback, link-local incl. cloud metadata,
     * CGNAT, benchmark, multicast, reserved, broadcast).
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /**
     * Blocked IPv6 ranges (unspecified, loopback, IPv4-mapped/-compatible, NAT64,
     * discard, Teredo, documentation, 6to4, ULA, link-local, site-local, multicast).
     */
    private const BLOCKED_V6 = [
        '::/96', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * @param  (callable(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /**
     * Throws when the URL is not https or its host resolves to a non-public address.
     *
     * @return array{host:string,port:int,ip:string}
     */
    public static function assertPublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(__('sso.error_https', ['url' => $url]));
        }

        $host = self::asciiHost((string) $parts['host']);
        $port = (int) ($parts['port'] ?? 443);
        $literal = trim($host, '[]');
        $ips = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : self::resolve($host);

        if ($ips === []) {
            throw new RuntimeException(__('sso.error_host_unresolved', ['host' => $host]));
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new RuntimeException(__('sso.error_host_private', ['host' => $host]));
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * The URL with its host as lowercase ASCII (IDN converted), i.e. exactly the
     * host curl connects to. Stored endpoints are kept in this form.
     */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['host'] ?? '') === '') {
            throw new RuntimeException(__('sso.error_https', ['url' => $url]));
        }

        $host = self::asciiHost((string) $parts['host']);
        $pos = strpos($url, (string) $parts['host']);

        return $pos === false ? $url : substr_replace($url, $host, $pos, strlen((string) $parts['host']));
    }

    /**
     * Lowercase ASCII host; internationalized names are converted (UTS #46), failures rejected.
     */
    public static function asciiHost(string $host): string
    {
        if (str_starts_with($host, '[')) {
            return strtolower($host);
        }
        if (preg_match('/^[\x21-\x7E]+$/', $host)) {
            $ascii = strtolower($host);
        } else {
            $ascii = function_exists('idn_to_ascii')
                ? idn_to_ascii(mb_strtolower($host, 'UTF-8'), IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46)
                : false;
        }

        if (! is_string($ascii) || $ascii === '' || ! preg_match('/^[a-z0-9.-]+$/', $ascii)) {
            throw new RuntimeException(__('sso.error_host_invalid', ['host' => $host]));
        }

        return $ascii;
    }

    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        foreach (strlen($bin) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }

        // IPv6: only global unicast (2000::/3).
        return strlen($bin) === 4 || (ord($bin[0]) & 0xE0) === 0x20;
    }

    /**
     * HTTP client for one request to a checked URL. In strict mode the URL is
     * checked, the resolved IP pinned and redirects disabled.
     */
    public static function client(string $url, bool $strict, int $timeout = 10, bool $followRedirects = false): PendingRequest
    {
        $options = [
            // A truthy return aborts the transfer above the size limit (also without Content-Length).
            'progress' => static fn ($total, $downloaded) => $downloaded > self::MAX_BYTES,
        ];

        $http = Http::timeout($timeout)->connectTimeout(min(5, $timeout))->acceptJson();

        if ($strict) {
            $target = self::assertPublic($url);
            // The pinned host must be the one curl uses: the URL has to be normalized already.
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $target['host']) {
                throw new RuntimeException(__('sso.error_host_invalid', ['host' => (string) parse_url($url, PHP_URL_HOST)]));
            }
            $options['protocols'] = ['https'];
            // Direct connection only: an (environment) proxy would resolve the host itself.
            $options['proxy'] = '';
            if (filter_var(trim($target['host'], '[]'), FILTER_VALIDATE_IP) === false) {
                // Connect to the checked address only (no second DNS lookup, no rebinding).
                $options['curl'] = [CURLOPT_RESOLVE => [self::resolveEntry($target)]];
            }
            $followRedirects = false;
        }

        if (! $followRedirects) {
            $http = $http->withoutRedirecting();
        }

        return $http->withOptions($options);
    }

    /**
     * CURLOPT_RESOLVE entry "host:port:address" for a checked target.
     *
     * @param  array{host:string,port:int,ip:string}  $target
     */
    public static function resolveEntry(array $target): string
    {
        $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];

        return $target['host'].':'.$target['port'].':'.$ip;
    }

    /**
     * Second check after the response (also covers faked responses in tests).
     */
    public static function assertSize(Response $response): void
    {
        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new RuntimeException(__('sso.error_response_size'));
        }
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }

        $ips = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
