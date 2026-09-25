<?php

require_once __DIR__ . '/Http.php';

/**
 * Quick-listen links for a track on Spotify and YouTube Music, so you don't
 * have to leave the dashboard and search for it yourself.
 *
 * Works with zero configuration: each service gets a plain search-results
 * link ("search" — takes you to a results page, not the exact track).
 * If you provide API credentials in config.php, it upgrades to a verified
 * direct link to the exact track/video ("direct") via a real lookup,
 * cached for 30 days per track since a track's link doesn't change.
 *
 * Spotify needs a free Client ID/Secret from
 * https://developer.spotify.com/dashboard (Client Credentials flow — no
 * user login involved, just app-level access to search). As of Spotify's
 * current API policy, the account that owns the app also needs an active
 * Spotify Premium subscription for its Search endpoint to work in
 * Development Mode — without one, Spotify's API returns an error and this
 * silently falls back to the search link (confirmed in testing: token
 * exchange succeeds, but the search call itself is rejected with "Active
 * premium subscription required for the owner of the app").
 *
 * YouTube needs a free API key from https://console.cloud.google.com
 * (enable "YouTube Data API v3"). Its free quota is limited (100
 * units/day, and a search costs 100 units), so this is genuinely optional —
 * the search-link fallback is completely serviceable on its own.
 */
class ListenLinks
{
    private array $config;
    private string $cacheDir;

    public function __construct(array $config, string $rootDir)
    {
        $this->config = $config;
        $this->cacheDir = $rootDir . '/cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * @return array{
     *     spotify: array{url: string, verified: bool},
     *     youtube: array{url: string, verified: bool}
     * }
     */
    public function forTrack(string $artist, string $track): array
    {
        if ($artist === '' || $track === '') {
            return [
                'spotify' => ['url' => '', 'verified' => false],
                'youtube' => ['url' => '', 'verified' => false],
            ];
        }

        return [
            'spotify' => $this->spotifyLink($artist, $track),
            'youtube' => $this->youtubeLink($artist, $track),
        ];
    }

    private function spotifyLink(string $artist, string $track): array
    {
        $searchFallback = 'https://open.spotify.com/search/' . rawurlencode($artist . ' ' . $track);

        $clientId = $this->config['spotify_client_id'] ?? '';
        $clientSecret = $this->config['spotify_client_secret'] ?? '';
        if ($clientId === '' || $clientSecret === '') {
            return ['url' => $searchFallback, 'verified' => false];
        }

        $cacheKey = 'listen_spotify_' . md5(strtolower($artist . '|' . $track));
        $cached = $this->readCache($cacheKey, 2592000);
        if ($cached !== null) {
            return $cached['url']
                ? ['url' => $cached['url'], 'verified' => true]
                : ['url' => $searchFallback, 'verified' => false];
        }

        $url = null;
        $token = $this->spotifyToken($clientId, $clientSecret);

        if ($token !== null) {
            $query = 'track:' . $track . ' artist:' . $artist;
            $apiUrl = 'https://api.spotify.com/v1/search?type=track&limit=1&q=' . rawurlencode($query);
            $response = Http::get($apiUrl, ['Authorization: Bearer ' . $token]);

            if ($response !== null) {
                $data = json_decode($response, true);
                $url = $data['tracks']['items'][0]['external_urls']['spotify'] ?? null;
            }
        }

        $this->writeCache($cacheKey, ['url' => $url]);

        return $url
            ? ['url' => $url, 'verified' => true]
            : ['url' => $searchFallback, 'verified' => false];
    }

    private function spotifyToken(string $clientId, string $clientSecret): ?string
    {
        $cached = $this->readCache('listen_spotify_token', 3300); // Spotify tokens last 3600s
        if ($cached !== null) {
            return $cached['token'] ?? null;
        }

        $response = Http::post(
            'https://accounts.spotify.com/api/token',
            ['grant_type' => 'client_credentials'],
            ['Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)]
        );

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        $token = $data['access_token'] ?? null;

        $this->writeCache('listen_spotify_token', ['token' => $token]);

        return $token;
    }

    private function youtubeLink(string $artist, string $track): array
    {
        $searchFallback = 'https://music.youtube.com/search?q=' . rawurlencode($artist . ' ' . $track);

        $apiKey = $this->config['youtube_api_key'] ?? '';
        if ($apiKey === '') {
            return ['url' => $searchFallback, 'verified' => false];
        }

        $cacheKey = 'listen_youtube_' . md5(strtolower($artist . '|' . $track));
        $cached = $this->readCache($cacheKey, 2592000);
        if ($cached !== null) {
            return $cached['url']
                ? ['url' => $cached['url'], 'verified' => true]
                : ['url' => $searchFallback, 'verified' => false];
        }

        $query = $artist . ' ' . $track . ' official audio';
        $apiUrl = 'https://www.googleapis.com/youtube/v3/search?part=snippet&maxResults=1&type=video&q='
            . rawurlencode($query) . '&key=' . rawurlencode($apiKey);
        $response = Http::get($apiUrl);
        $url = null;

        if ($response !== null) {
            $data = json_decode($response, true);
            $videoId = $data['items'][0]['id']['videoId'] ?? null;
            if ($videoId) {
                $url = 'https://music.youtube.com/watch?v=' . $videoId;
            }
        }

        $this->writeCache($cacheKey, ['url' => $url]);

        return $url
            ? ['url' => $url, 'verified' => true]
            : ['url' => $searchFallback, 'verified' => false];
    }

    private function readCache(string $key, int $ttl): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        if (is_file($file) && (time() - filemtime($file)) < $ttl) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    private function writeCache(string $key, array $data): void
    {
        @file_put_contents($this->cacheDir . '/' . $key . '.json', json_encode($data));
    }
}
