<?php

require_once __DIR__ . '/Http.php';

/**
 * Checks whether a hostname resolves with DNSSEC validation, for the
 * footer's DNSSEC tick/cross. Asks Cloudflare's DNS-over-HTTPS resolver
 * (a validating resolver) and reads the AD ("authenticated data") flag,
 * which is only set when the whole chain of trust from the root verifies.
 * Cached for a day per host, since DNSSEC status almost never changes.
 */
class Dnssec
{
    private const TTL = 86400;

    /**
     * @return bool|null null when the host can't be checked (localhost, a
     *                   bare IP, or the resolver didn't answer)
     */
    public static function isSigned(string $host): ?bool
    {
        $host = strtolower(rtrim($host, '.'));
        if (!self::isCheckableHost($host)) {
            return null;
        }

        $cacheFile = __DIR__ . '/../cache/dnssec_' . md5($host) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('signed', $cached)) {
                return $cached['signed'];
            }
        }

        $response = Http::get(
            'https://cloudflare-dns.com/dns-query?name=' . rawurlencode($host) . '&type=A&do=1',
            ['Accept: application/dns-json'],
            4
        );
        $data = $response !== null ? json_decode($response, true) : null;
        if (!is_array($data) || ($data['Status'] ?? -1) !== 0) {
            // Don't cache a failed lookup; try again next request.
            return null;
        }

        $signed = !empty($data['AD']);

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode(['signed' => $signed]));

        return $signed;
    }

    /**
     * The Host header is client-supplied, so only look up things that are
     * plausibly real public domain names.
     */
    public static function isCheckableHost(string $host): bool
    {
        return strpos($host, '.') !== false
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
    }
}
