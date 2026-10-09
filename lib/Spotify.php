<?php

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/AppleMusic.php';

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

    /**
     * Searches Spotify for a track, returning its canonical Spotify URL and
     * largest available album art, or null if not configured, the request
     * itself failed (don't cache an infrastructure hiccup as a permanent
     * miss), or there's genuinely no match. Cached 30 days per track once a
     * real, complete answer comes back either way — shared by ListenLinks
     * (the quick-listen link) and art-fallback resolution, so asking for
     * both on the same track costs one Spotify search, not two.
     *
     * @return array{url: string, art: string}|null
     */
    public function searchTrack(string $artist, string $track): ?array
    {
        if (!$this->isConfigured() || $artist === '' || $track === '') {
            return null;
        }

        $cacheKey = 'spotify_track_' . md5(strtolower($artist . '|' . $track));
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('found', $cached)) {
                return $cached['found'] ? $cached['data'] : null;
            }
        }

        $token = $this->getToken();
        if ($token === null) {
            return null;
        }

        $query = 'track:' . $track . ' artist:' . $artist;
        $apiUrl = 'https://api.spotify.com/v1/search?type=track&limit=1&q=' . rawurlencode($query);
        $response = Http::get($apiUrl, ['Authorization: Bearer ' . $token]);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        $item = $data['tracks']['items'][0] ?? null;

        $result = null;
        if ($item) {
            $images = $item['album']['images'] ?? []; // Spotify lists these largest-first
            $result = [
                'url' => $item['external_urls']['spotify'] ?? '',
                'art' => $images[0]['url'] ?? '',
            ];
        }

        @file_put_contents($cacheFile, json_encode(['found' => $result !== null, 'data' => $result]));

        return $result;
    }

    /**
     * A track's album art, trying Last.fm first, then Spotify, then Apple
     * Music — each only as a fallback for the one before it, since every
     * catalog has real gaps (soundtrack/compilation scrobbles, obscure or
     * mismatched tracks) that another one often covers. Returns '' rather
     * than guessing if none of the three has it.
     */
    public function resolveTrackArt(
        LastFm $lastfm,
        string $artist,
        string $track,
        array $rawImage = [],
        ?AppleMusic $appleMusic = null
    ): string {
        $art = LastFm::bestImage($rawImage);
        if ($art !== '') {
            return $art;
        }

        $art = $lastfm->getTrackArt($artist, $track);
        if ($art !== '') {
            return $art;
        }

        $match = $this->searchTrack($artist, $track);
        if (!empty($match['art'])) {
            return $match['art'];
        }

        if ($appleMusic !== null) {
            $appleMatch = $appleMusic->searchTrack($artist, $track);
            if (!empty($appleMatch['art'])) {
                return $appleMatch['art'];
            }
        }

        return '';
    }
}
