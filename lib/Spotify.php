<?php

require_once __DIR__ . '/Http.php';

/**
 * Shared Spotify Client Credentials auth (no user login, just app-level
 * access), used by both ListenLinks (quick listen links) and Widgets
 * (verifying a scrobbled "artist" name is really a Spotify artist, for
 * Before They Were Famous) so the token fetch/cache logic lives in one
 * place rather than being duplicated per caller.
 */
class Spotify
{
    private string $clientId;
    private string $clientSecret;
    private string $cacheDir;

    public function __construct(string $clientId, string $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->cacheDir = __DIR__ . '/../cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function getToken(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $file = $this->cacheDir . '/spotify_token.json';
        if (is_file($file) && (time() - filemtime($file)) < 3300) { // tokens last 3600s
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data['token'])) {
                return $data['token'];
            }
        }

        $response = Http::post(
            'https://accounts.spotify.com/api/token',
            ['grant_type' => 'client_credentials'],
            ['Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)]
        );

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        $token = $data['access_token'] ?? null;
        if ($token === null) {
            return null;
        }

        @file_put_contents($file, json_encode(['token' => $token]));

        return $token;
    }
}
