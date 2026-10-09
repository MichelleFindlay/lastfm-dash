<?php

require_once __DIR__ . '/Http.php';

/**
 * Looks up a track on Apple Music via Apple's free, unauthenticated iTunes
 * Search API (itunes.apple.com/search) — no credentials needed, unlike
 * Spotify/YouTube. Shared by ListenLinks (the quick-listen button) and
 * album-art fallback resolution, so asking for both on the same track costs
 * one search, not two.
 *
 * That API only takes a loose free-text term rather than separate
 * artist/track fields, so it occasionally ranks an unrelated same-titled
 * track above the real match (or omits it) — handled by only accepting a
 * result whose artist name actually matches ours, so a genuine miss is
 * reported as no match rather than risking a confidently wrong one.
 */
class AppleMusic
{
    private string $cacheDir;

    public function __construct(string $rootDir)
    {
        $this->cacheDir = $rootDir . '/cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * @return array{url: string, art: string, explicit: bool}|null
     */
    public function searchTrack(string $artist, string $track): ?array
    {
        if ($artist === '' || $track === '') {
            return null;
        }

        $cacheKey = 'apple_track_' . md5(strtolower($artist . '|' . $track));
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            // Matches cached before the explicit flag was added are refetched
            // once rather than reported as not explicit for up to 30 days.
            if (is_array($cached) && array_key_exists('found', $cached)
                && (!$cached['found'] || array_key_exists('explicit', $cached['data'] ?? []))) {
                return $cached['found'] ? $cached['data'] : null;
            }
        }

        $apiUrl = 'https://itunes.apple.com/search?media=music&entity=song&limit=5&term='
            . rawurlencode($artist . ' ' . $track);
        $response = Http::get($apiUrl);

        if ($response === null) {
            // The request itself failed — an infrastructure hiccup, not a
            // genuine "no match". Don't cache it.
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['results'])) {
            // Rate-limited (Apple answers a 403 with no JSON body) or
            // otherwise not a real answer — don't cache it as "no match".
            return null;
        }
        $results = $data['results'];

        $result = null;
        foreach ($results as $item) {
            if (strcasecmp(trim((string) ($item['artistName'] ?? '')), $artist) === 0) {
                $result = [
                    'url' => $item['trackViewUrl'] ?? '',
                    'art' => self::upscaleArtwork($item['artworkUrl100'] ?? ''),
                    // "explicit", "cleaned" (the edited version) or "notExplicit"
                    'explicit' => ($item['trackExplicitness'] ?? '') === 'explicit',
                ];
                break;
            }
        }

        // Only reaching here means Apple gave a real, complete answer —
        // worth caching either way, including a genuine "no match".
        @file_put_contents($cacheFile, json_encode(['found' => $result !== null, 'data' => $result]));

        return $result;
    }

    /**
     * iTunes artwork URLs encode their size in the path (".../100x100bb.jpg")
     * — swap in a larger one for actual display use instead of the thumbnail
     * size search results come with.
     */
    private static function upscaleArtwork(string $url): string
    {
        if ($url === '') {
            return '';
        }

        return preg_replace('#/\d+x\d+bb\.#', '/600x600bb.', $url) ?? $url;
    }
}
