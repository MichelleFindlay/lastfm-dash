<?php

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Spotify.php';

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
 * (enable "YouTube Data API v3"). Its free quota is limited (10,000
 * units/day by default, and a search costs 100 units — ~100 searches/day),
 * so this is genuinely optional — the search-link fallback is completely
 * serviceable on its own. A local counter (youtube_daily_limit in
 * config.php, default 100) caps how many live lookups are made in any
 * 24-hour window, falling back to the search link once it's reached rather
 * than risking the key getting rate-limited or suspended by Google.
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

        $spotify = new Spotify($this->config['spotify_client_id'] ?? '', $this->config['spotify_client_secret'] ?? '');
        if (!$spotify->isConfigured()) {
            return ['url' => $searchFallback, 'verified' => false];
        }

        $cacheKey = 'listen_spotify_' . md5(strtolower($artist . '|' . $track));
        $cached = $this->readCache($cacheKey, 2592000);
        if ($cached !== null) {
            return $cached['url']
                ? ['url' => $cached['url'], 'verified' => true]
                : ['url' => $searchFallback, 'verified' => false];
        }

        $token = $spotify->getToken();
        if ($token === null) {
            // Couldn't even authenticate — an infrastructure hiccup, not a
            // genuine "no match". Don't cache it, so the next request tries
            // fresh instead of being stuck on the search fallback for 30
            // days over a transient failure.
            return ['url' => $searchFallback, 'verified' => false];
        }

        $query = 'track:' . $track . ' artist:' . $artist;
        $apiUrl = 'https://api.spotify.com/v1/search?type=track&limit=1&q=' . rawurlencode($query);
        $response = Http::get($apiUrl, ['Authorization: Bearer ' . $token]);

        if ($response === null) {
            // The request itself failed (network error, Spotify outage,
            // etc.) — same reasoning as above.
            return ['url' => $searchFallback, 'verified' => false];
        }

        $data = json_decode($response, true);
        $url = $data['tracks']['items'][0]['external_urls']['spotify'] ?? null;

        // Only reaching here means Spotify gave a real, complete answer —
        // worth caching either way, including a genuine "no match".
        $this->writeCache($cacheKey, ['url' => $url]);

        return $url
            ? ['url' => $url, 'verified' => true]
            : ['url' => $searchFallback, 'verified' => false];
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

        // Quota-limited: fall back without touching the per-track cache, so
        // this track gets a fresh real attempt once the window resets
        // instead of being stuck "unverified" for 30 days over a temporary
        // limit.
        if (!$this->youtubeQuotaAvailable()) {
            return ['url' => $searchFallback, 'verified' => false];
        }

        $query = $artist . ' ' . $track . ' official audio';
        $apiUrl = 'https://www.googleapis.com/youtube/v3/search?part=snippet&maxResults=1&type=video&q='
            . rawurlencode($query) . '&key=' . rawurlencode($apiKey);
        $response = Http::get($apiUrl);

        if ($response === null) {
            // The request itself failed (network error, quota rejection,
            // etc.) — not a genuine "no match". Don't cache it, so the next
            // request tries fresh.
            return ['url' => $searchFallback, 'verified' => false];
        }

        $data = json_decode($response, true);
        $videoId = $data['items'][0]['id']['videoId'] ?? null;
        $url = $videoId ? ('https://music.youtube.com/watch?v=' . $videoId) : null;

        // Only reaching here means YouTube gave a real, complete answer —
        // worth caching either way, including a genuine "no match".
        $this->writeCache($cacheKey, ['url' => $url]);

        return $url
            ? ['url' => $url, 'verified' => true]
            : ['url' => $searchFallback, 'verified' => false];
    }

    /**
     * Whether a live YouTube API call is still allowed in the current
     * 24-hour window, incrementing the counter if so. A fixed window that
     * restarts from zero 24 hours after its first call — not a true sliding
     * window — which is a deliberate simplification: the goal is just
     * staying comfortably clear of the daily quota, not perfectly even
     * pacing.
     */
    private function youtubeQuotaAvailable(): bool
    {
        $limit = max(1, (int) ($this->config['youtube_daily_limit'] ?? 100));
        $file = $this->cacheDir . '/youtube_quota.json';
        $now = time();

        $state = ['window_start' => $now, 'count' => 0];
        if (is_file($file)) {
            $existing = json_decode((string) file_get_contents($file), true);
            if (is_array($existing) && isset($existing['window_start'], $existing['count'])) {
                $state = $existing;
            }
        }

        if ($now - $state['window_start'] >= 86400) {
            $state = ['window_start' => $now, 'count' => 0];
        }

        if ($state['count'] >= $limit) {
            return false;
        }

        $state['count']++;
        @file_put_contents($file, json_encode($state));

        return true;
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
