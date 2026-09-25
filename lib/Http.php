<?php

/**
 * Tiny HTTP GET helper that works whether or not the curl extension is
 * enabled — some shared PHP hosts ship without it, which would otherwise
 * fatal-error with "Call to undefined function curl_init()".
 */
class Http
{
    public static function get(string $url, array $headers = [], int $timeout = 8): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            return ($response === false || $error) ? null : $response;
        }

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'header'  => implode("\r\n", $headers),
                ],
            ]);
            $response = @file_get_contents($url, false, $context);

            return $response === false ? null : $response;
        }

        return null;
    }
}
