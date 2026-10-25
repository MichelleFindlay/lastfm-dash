<?php

/**
 * HTTP security headers (HSTS, CSP, X-Frame-Options, etc.) sent by every
 * entry point, so the site scores A+ on securityheaders.com without relying
 * on the host's web server config, which shared hosting often won't let you
 * touch. The dashboard page gets a nonce-based CSP for its one inline
 * <script>; the JSON endpoints get a deny-everything policy, since they
 * never render anything.
 */
class SecurityHeaders
{
    /** Headers securityheaders.com grades on; all must be present for an A+. */
    private const GRADED = [
        'strict-transport-security',
        'content-security-policy',
        'x-frame-options',
        'x-content-type-options',
        'referrer-policy',
        'permissions-policy',
    ];

    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    public static function sendForPage(): void
    {
        // style-src keeps 'unsafe-inline' because the markup toggles
        // visibility with style="display:none" attributes; scripts are
        // nonce-only, which is the part that matters against XSS. img-src
        // allows any https host since album art comes from Last.fm,
        // Spotify, Apple, Deezer and Wikipedia CDNs.
        self::send(implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . self::nonce() . "'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' https: data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            'upgrade-insecure-requests',
        ]), 'credentialless');
    }

    public static function sendForApi(): void
    {
        self::send("default-src 'none'; frame-ancestors 'none'", 'require-corp');
    }

    /**
     * Whether every graded header is set on the current response — what the
     * footer's tick/cross reflects.
     */
    public static function allPresent(): bool
    {
        $sent = [];
        foreach (headers_list() as $line) {
            $sent[strtolower(trim(strstr($line, ':', true)))] = true;
        }
        foreach (self::GRADED as $name) {
            if (empty($sent[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The page uses COEP credentialless rather than require-corp: album art
     * comes from third-party CDNs that don't send Cross-Origin-Resource-Policy,
     * and require-corp would block every one of those images. credentialless
     * still loads them, just without cookies.
     */
    private static function send(string $csp, string $coep): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('Content-Security-Policy: ' . $csp);
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cross-Origin-Embedder-Policy: ' . $coep);
    }
}
