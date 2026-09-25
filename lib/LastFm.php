<?php

require_once __DIR__ . '/Http.php';

/**
 * Minimal Last.fm API client with simple file-based response caching.
 */
class LastFm
{
    /**
     * Community tags that show up constantly on Last.fm but aren't genres
     * (personal ratings, meta-commentary, list names). Filtered out of the
     * genre breakdown so it doesn't fill up with noise like "seen live".
     */
    private const GENRE_BLOCKLIST = [
        'seen live', 'favorites', 'favourite', 'favourites', 'awesome',
        'love', 'beautiful', 'amazing', 'good', 'great', 'own it', 'super',
        'check out', 'cool', 'legend', 'legends', 'to listen', 'listened to',
        'albums i own', 'my music', 'spotify', 'usa', 'uk', 'british',
        'american', 'male vocalists', 'female vocalists',
    ];

    private string $apiKey;
    private string $user;
    private int $cacheTtl;
    private string $cacheDir;

    public function __construct(string $apiKey, string $user, int $cacheTtl = 60)
    {
        $this->apiKey = $apiKey;
        $this->user = $user;
        $this->cacheTtl = $cacheTtl;
        $this->cacheDir = __DIR__ . '/../cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Call a Last.fm API method, transparently caching the JSON response.
     * Pass $ttlOverride for calls (like per-track art lookups) that should
     * be cached far longer than the general API response TTL.
     */
    public function call(string $method, array $params = [], ?int $ttlOverride = null): ?array
    {
        $ttl = $ttlOverride ?? $this->cacheTtl;

        $params = array_merge([
            'method'   => $method,
            'user'     => $this->user,
            'api_key'  => $this->apiKey,
            'format'   => 'json',
        ], $params);

        $cacheFile = $this->cacheDir . '/' . md5($method . serialize($params)) . '.json';

        if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = 'https://ws.audioscrobbler.com/2.0/?' . http_build_query($params);
        $response = Http::get($url, ['User-Agent: lastfm-dash/1.0']);

        if ($response === null) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            return null;
        }

        if ($ttl > 0) {
            @file_put_contents($cacheFile, $response);
        }

        return $decoded;
    }

    public function getRecentTracks(int $limit = 1): ?array
    {
        return $this->call('user.getrecenttracks', ['limit' => $limit, 'extended' => 1]);
    }

    public function getTopTracks(string $period, int $limit): ?array
    {
        return $this->call('user.gettoptracks', ['period' => $period, 'limit' => $limit]);
    }

    /**
     * Tracks scrobbled during the current chart week, sorted by playcount.
     */
    public function getWeeklyTrackChart(int $limit): array
    {
        $data = $this->call('user.getweeklytrackchart', []);
        $tracks = $data['weeklytrackchart']['track'] ?? [];

        if (isset($tracks['name'])) {
            $tracks = [$tracks]; // API returns a single object (not an array) for exactly one track
        }

        usort($tracks, fn($a, $b) => (int) ($b['playcount'] ?? 0) <=> (int) ($a['playcount'] ?? 0));

        return array_slice($tracks, 0, $limit);
    }

    /**
     * The album's own artwork, fetched independently of the track's
     * artwork so the two can be shown side by side even when they differ.
     */
    public function getAlbumArt(string $artist, string $album): string
    {
        if ($artist === '' || $album === '') {
            return '';
        }

        $data = $this->call('album.getinfo', ['artist' => $artist, 'album' => $album]);

        return self::bestImage($data['album']['image'] ?? []);
    }

    /**
     * Real artwork for a track, via its associated album. Last.fm's
     * user.getTopTracks / getWeeklyTrackChart responses mostly return a
     * generic placeholder image now (per-track art was deprecated), so this
     * does a track.getInfo lookup instead, which still resolves the track's
     * album art in most cases. Cached for a full day since art rarely
     * changes, independent of the app's general API cache TTL.
     */
    public function getTrackArt(string $artist, string $track): string
    {
        if ($artist === '' || $track === '') {
            return '';
        }

        $data = $this->call('track.getinfo', ['artist' => $artist, 'track' => $track], 86400);

        return self::bestImage($data['track']['album']['image'] ?? []);
    }

    /**
     * A genre breakdown derived from your top artists' community tags,
     * since Last.fm has no direct "genre" concept for a user. Each artist's
     * top tags are weighted by how much you've played that artist, then
     * aggregated into percentages. Tag lookups are cached for a week since
     * they rarely change, independent of the app's general API cache TTL.
     *
     * @return array<int, array{name: string, pct: float}>
     */
    public function getTopGenres(string $period, int $artistLimit, int $genreLimit): array
    {
        $top = $this->call('user.gettopartists', ['period' => $period, 'limit' => $artistLimit]);
        $artists = $top['topartists']['artist'] ?? [];

        if (isset($artists['name'])) {
            $artists = [$artists]; // API returns a single object (not an array) for exactly one artist
        }

        $scores = [];

        foreach ($artists as $artist) {
            $name = $artist['name'] ?? '';
            $playcount = (int) ($artist['playcount'] ?? 0);
            if ($name === '' || $playcount === 0) {
                continue;
            }

            $tagsData = $this->call('artist.gettoptags', ['artist' => $name], 604800);
            $tags = $tagsData['toptags']['tag'] ?? [];

            if (isset($tags['name'])) {
                $tags = [$tags];
            }

            foreach (array_slice($tags, 0, 5) as $tag) {
                $tagName = strtolower(trim($tag['name'] ?? ''));
                if ($tagName === '' || in_array($tagName, self::GENRE_BLOCKLIST, true)) {
                    continue;
                }

                // Last.fm's tag "count" is a 0-100 relevance rank, not a play count.
                $weight = max(1, (int) ($tag['count'] ?? 0));
                $scores[$tagName] = ($scores[$tagName] ?? 0) + ($weight * $playcount);
            }
        }

        arsort($scores);
        $total = array_sum($scores);

        if ($total <= 0) {
            return [];
        }

        $result = [];
        foreach (array_slice($scores, 0, $genreLimit, true) as $name => $score) {
            $result[] = [
                'name' => ucwords($name),
                'pct'  => round($score / $total * 100, 1),
            ];
        }

        $shownTotal = array_sum(array_column($result, 'pct'));
        if (count($scores) > $genreLimit && $shownTotal < 99.5) {
            $result[] = ['name' => 'Other', 'pct' => round(100 - $shownTotal, 1)];
        }

        return $result;
    }

    /**
     * Pick the largest available artwork URL from a Last.fm image array,
     * ignoring Last.fm's generic "no artwork" placeholder graphic.
     */
    public static function bestImage(array $images): string
    {
        $bySize = [];
        foreach ($images as $img) {
            if (!empty($img['#text']) && strpos($img['#text'], '2a96cbd8b46e442fc41c2b86b821562f') === false) {
                $bySize[$img['size'] ?? ''] = $img['#text'];
            }
        }

        foreach (['extralarge', 'large', 'medium', 'small', ''] as $size) {
            if (!empty($bySize[$size])) {
                return $bySize[$size];
            }
        }

        return '';
    }
}
