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

    /**
     * Spotify's own official genre-seed vocabulary — the fixed list it used
     * to expose via its (since-retired) available-genre-seeds endpoint.
     * Used to filter Genre Breakdown down to tags that are recognizably real
     * genres rather than Last.fm community-tag noise (artist names, list
     * titles, "seen live"-style meta-tags that GENRE_BLOCKLIST doesn't
     * happen to cover). Hyphens are normalized to spaces for comparison, so
     * e.g. Spotify's "hip-hop" matches Last.fm's "hip hop" tag spelling.
     */
    private const SPOTIFY_GENRE_SEEDS = [
        'acoustic', 'afrobeat', 'alt-rock', 'alternative', 'ambient', 'anime',
        'black-metal', 'bluegrass', 'blues', 'bossanova', 'brazil', 'breakbeat',
        'british', 'cantopop', 'chicago-house', 'children', 'chill', 'classical',
        'club', 'comedy', 'country', 'dance', 'dancehall', 'death-metal',
        'deep-house', 'detroit-techno', 'disco', 'disney', 'drum-and-bass', 'dub',
        'dubstep', 'edm', 'electro', 'electronic', 'emo', 'folk', 'forro',
        'french', 'funk', 'garage', 'german', 'gospel', 'goth', 'grindcore',
        'groove', 'grunge', 'guitar', 'happy', 'hard-rock', 'hardcore',
        'hardstyle', 'heavy-metal', 'hip-hop', 'holidays', 'honky-tonk', 'house',
        'idm', 'indian', 'indie', 'indie-pop', 'industrial', 'iranian',
        'j-dance', 'j-idol', 'j-pop', 'j-rock', 'jazz', 'k-pop', 'kids',
        'latin', 'latino', 'malay', 'mandopop', 'metal', 'metal-misc',
        'metalcore', 'minimal-techno', 'movies', 'mpb', 'new-age', 'new-release',
        'opera', 'pagode', 'party', 'philippines-opm', 'piano', 'pop',
        'pop-film', 'post-dubstep', 'power-pop', 'progressive-house', 'psych-rock',
        'punk', 'punk-rock', 'r-n-b', 'rainy-day', 'reggae', 'reggaeton',
        'road-trip', 'rock', 'rock-n-roll', 'rockabilly', 'romance', 'sad',
        'salsa', 'samba', 'sertanejo', 'show-tunes', 'singer-songwriter', 'ska',
        'sleep', 'songwriter', 'soul', 'soundtracks', 'spanish', 'study',
        'summer', 'swedish', 'synth-pop', 'tango', 'techno', 'trance',
        'trip-hop', 'turkish', 'work-out', 'world-music',
    ];

    /**
     * A few common Last.fm tag spellings that don't literally match a
     * Spotify seed even after hyphen normalization, mapped onto the seed
     * they mean — mainly so "Rnb"/"Dnb"/"Drum & Bass"/"Drum N Bass" collapse
     * onto the same genre instead of being rejected as unrecognized (or
     * counted as separate slices, which was its own source of noise).
     */
    private const GENRE_ALIASES = [
        'rnb'         => 'r&b',
        'r & b'       => 'r&b',
        'dnb'         => 'drum and bass',
        'drum n bass' => 'drum and bass',
        'drum & bass' => 'drum and bass',
        'hiphop'      => 'hip hop',
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
     * Pass $cacheOnly to read a cached response without ever making a live
     * request — used when a page request needs to know "do we already know
     * this" without risking a slow/expensive live call of its own; a
     * background job (cron.php) is what actually fetches missing ones.
     */
    public function call(string $method, array $params = [], ?int $ttlOverride = null, bool $cacheOnly = false): ?array
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

        if ($cacheOnly) {
            return null;
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

    /**
     * Lifetime account stats: total scrobbles, unique artist/album/track
     * counts, and registration date. Cached for 15 minutes regardless of
     * the instance's general cache_ttl, so it's consistent everywhere it's
     * read from (the main page, the now-playing poll, cron.php) — this is
     * the number the Lifetime Stats panel shows, so it shouldn't refresh on
     * a different schedule depending on who happened to ask first.
     */
    public function getInfo(): ?array
    {
        $data = $this->call('user.getinfo', [], 900);

        return $data['user'] ?? null;
    }

    /**
     * Formats getInfo() into the display-ready stats shown in the Lifetime
     * Stats panel and pushed to the client on every "now playing" poll, so
     * the numbers tick up as new scrobbles land instead of only refreshing
     * on a full page reload.
     */
    public static function formatLifetimeStats(?array $userInfo): array
    {
        if (!$userInfo) {
            return [];
        }

        $playcount = (int) ($userInfo['playcount'] ?? 0);
        $registeredUnix = (int) ($userInfo['registered']['unixtime'] ?? 0);
        $daysSince = $registeredUnix > 0 ? max(1, (int) floor((time() - $registeredUnix) / 86400)) : 0;

        return [
            'scrobbles'    => number_format($playcount),
            'avg_day'      => $daysSince > 0 ? number_format($playcount / $daysSince, 1) : '—',
            'artists'      => number_format((int) ($userInfo['artist_count'] ?? 0)),
            'albums'       => number_format((int) ($userInfo['album_count'] ?? 0)),
            'tracks'       => number_format((int) ($userInfo['track_count'] ?? 0)),
            'member_since' => $registeredUnix > 0 ? date('j M Y', $registeredUnix) : '—',
        ];
    }

    /**
     * Reads/writes a JSON-serializable value from the file cache under an
     * arbitrary key, for caching computed results (not just raw API
     * responses) — e.g. the whole genre breakdown, not just its sub-calls.
     */
    private function cached(string $key, int $ttl, callable $compute)
    {
        $cacheFile = $this->cacheDir . '/' . $key . '.json';

        if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $compute();

        if ($ttl > 0) {
            @file_put_contents($cacheFile, json_encode($result));
        }

        return $result;
    }

    /**
     * Cached GET for external (non-Last.fm) APIs used by a couple of
     * widgets — e.g. Deezer for BPM, which Last.fm doesn't expose. Reuses
     * the same file cache as the Last.fm API calls.
     */
    public function externalGet(string $url, int $ttl, array $headers = []): ?string
    {
        $cacheFile = $this->cacheDir . '/ext_' . md5($url) . '.json';

        if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = Http::get($url, $headers);
        if ($response === null) {
            return null;
        }

        if ($ttl > 0) {
            @file_put_contents($cacheFile, $response);
        }

        return $response;
    }

    public function getRecentTracks(int $limit = 1): ?array
    {
        return $this->call('user.getrecenttracks', ['limit' => $limit, 'extended' => 1]);
    }

    /**
     * Finds the track before the current one in a getRecentTracks() list.
     * Last.fm keeps a track marked "now playing" even after it's crossed
     * its own scrobble threshold mid-play, so the currently-playing track
     * often also appears as its own freshly-recorded scrobble right behind
     * it — naively taking index 1 would show the still-playing track as
     * "previously played". Skip any leading entries that match the current
     * track's artist + name before picking one.
     *
     * @param array<int, array> $tracks Full list from getRecentTracks(), current track first.
     */
    public static function findPreviousTrack(array $tracks, string $currentArtist, string $currentName): ?array
    {
        for ($i = 1; $i < count($tracks); $i++) {
            $candidate = $tracks[$i];
            $artist = $candidate['artist']['#text'] ?? ($candidate['artist']['name'] ?? '');
            $name = $candidate['name'] ?? '';

            if (strcasecmp($artist, $currentArtist) !== 0 || strcasecmp($name, $currentName) !== 0) {
                return $candidate;
            }
        }

        return null;
    }

    public function getTopTracks(string $period, int $limit): ?array
    {
        return $this->call('user.gettoptracks', ['period' => $period, 'limit' => $limit]);
    }

    /**
     * Resolves a configured IANA timezone name, falling back to the
     * server's own default if it's blank or invalid. Shared by every
     * "today" computation (genres, tracks) so they all agree on midnight.
     */
    public static function resolveTimezone(string $tzName): DateTimeZone
    {
        if ($tzName !== '') {
            try {
                return new DateTimeZone($tzName);
            } catch (Exception $e) {
                // fall through to server default
            }
        }

        return new DateTimeZone(date_default_timezone_get());
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
     * UI period keys (used by the genre breakdown's period picker) mapped
     * to Last.fm's own period values. Last.fm has no calendar-year, -month,
     * -week or single-day period, so "this_year"/"this_month"/"this_week"
     * use Last.fm's own rolling windows (its closest built-in equivalent)
     * and "today" is computed separately from actual today's scrobbles.
     */
    private const UI_PERIOD_MAP = [
        'all_time'   => 'overall',
        'this_year'  => '12month',
        'this_month' => '1month',
        'this_week'  => '7day',
    ];

    /**
     * Validates a UI period key (from config or a request param), falling
     * back to $default if it's not one of the five the period pickers
     * support. Single source of truth for that set of valid values.
     */
    public static function validUiPeriod(string $value, string $default = 'all_time'): string
    {
        return ($value === 'today' || array_key_exists($value, self::UI_PERIOD_MAP)) ? $value : $default;
    }

    /**
     * A genre breakdown derived from your top artists' community tags,
     * since Last.fm has no direct "genre" concept for a user. Each artist's
     * top tags are weighted by how much you've played that artist, then
     * aggregated into percentages. The whole result is cached for a day
     * (not just the underlying API calls), so it's computed once daily
     * rather than re-aggregated on every page load.
     *
     * @return array<int, array{name: string, pct: float}>
     */
    public function getTopGenres(string $period, int $artistLimit, int $genreLimit, bool $onlySpotifyGenres = false): array
    {
        $cacheKey = 'genres_' . md5($this->user . $period . $artistLimit . $genreLimit . ($onlySpotifyGenres ? '1' : '0'));

        return $this->cached($cacheKey, 86400, function () use ($period, $artistLimit, $genreLimit, $onlySpotifyGenres) {
            return $this->computeTopGenres($period, $artistLimit, $genreLimit, $onlySpotifyGenres);
        });
    }

    /**
     * Genre breakdown for a UI period key ("all_time" / "this_year" /
     * "this_month" / "this_week" / "today"), used by the interactive period
     * picker.
     */
    public function getGenresForUiPeriod(string $uiPeriod, int $artistLimit, int $genreLimit, DateTimeZone $tz, bool $onlySpotifyGenres = false): array
    {
        if ($uiPeriod === 'today') {
            return $this->getTodayGenres($genreLimit, $tz, $onlySpotifyGenres);
        }

        $period = self::UI_PERIOD_MAP[$uiPeriod] ?? 'overall';

        return $this->getTopGenres($period, $artistLimit, $genreLimit, $onlySpotifyGenres);
    }

    /**
     * Genre breakdown from just today's actual scrobbles, since Last.fm's
     * period parameter has no single-day granularity. Cached briefly (not a
     * full day, unlike the other periods) since "today" keeps changing as
     * you listen.
     */
    private function getTodayGenres(int $genreLimit, DateTimeZone $tz, bool $onlySpotifyGenres = false): array
    {
        $cacheKey = 'genres_today_' . md5($this->user . $genreLimit . $tz->getName() . ($onlySpotifyGenres ? '1' : '0'));

        return $this->cached($cacheKey, 900, function () use ($genreLimit, $tz, $onlySpotifyGenres) {
            $artistPlaycounts = $this->getArtistPlaycountsSince($this->todayStart($tz));

            if (empty($artistPlaycounts)) {
                return [];
            }

            return $this->genresFromScores($this->scoreGenreTags($artistPlaycounts, false, $onlySpotifyGenres), $genreLimit);
        });
    }

    /**
     * Top tracks for a UI period key ("all_time" / "this_year" /
     * "this_month" / "this_week" / "today"), used by the Favourite Tracks
     * and Trending period pickers. Returns a consistent shape regardless of
     * source:
     * {name, artist: {name}, playcount, image}.
     */
    public function getTracksForUiPeriod(string $uiPeriod, int $limit, DateTimeZone $tz): array
    {
        if ($uiPeriod === 'today') {
            return $this->getTodayTopTracks($limit, $tz);
        }

        $period = self::UI_PERIOD_MAP[$uiPeriod] ?? 'overall';
        $data = $this->call('user.gettoptracks', ['period' => $period, 'limit' => $limit]);
        $tracks = $data['toptracks']['track'] ?? [];

        if (isset($tracks['name'])) {
            $tracks = [$tracks];
        }

        return $tracks;
    }

    /**
     * Top tracks from just today's actual scrobbles. Cached briefly, same
     * reasoning as getTodayGenres().
     */
    private function getTodayTopTracks(int $limit, DateTimeZone $tz): array
    {
        $cacheKey = 'today_tracks_' . md5($this->user . $limit . $tz->getName());

        return $this->cached($cacheKey, 900, function () use ($limit, $tz) {
            $counts = $this->getTrackCountsSince($this->todayStart($tz));
            usort($counts, fn($a, $b) => $b['playcount'] <=> $a['playcount']);

            return array_slice(array_values($counts), 0, $limit);
        });
    }

    private function todayStart(DateTimeZone $tz): int
    {
        return (new DateTime('today', $tz))->getTimestamp();
    }

    /**
     * Raw scrobbles (with a real date, i.e. not the currently-playing entry)
     * from paginated recent tracks starting at $sinceUnix. Capped at 10
     * pages (2,000 scrobbles) as a safety limit; a single day is normally
     * well under one page.
     */
    private function getScrobblesSince(int $sinceUnix): array
    {
        $scrobbles = [];

        for ($page = 1; $page <= 10; $page++) {
            $data = $this->call('user.getrecenttracks', ['limit' => 200, 'page' => $page, 'from' => $sinceUnix], 300);
            $tracks = $data['recenttracks']['track'] ?? [];

            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }

            if (empty($tracks)) {
                break;
            }

            foreach ($tracks as $t) {
                if (isset($t['date']['uts'])) {
                    $scrobbles[] = $t;
                }
            }

            $totalPages = (int) ($data['recenttracks']['@attr']['totalPages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
        }

        return $scrobbles;
    }

    /**
     * @return array<string, int> artist name => scrobble count
     */
    private function getArtistPlaycountsSince(int $sinceUnix): array
    {
        $counts = [];

        foreach ($this->getScrobblesSince($sinceUnix) as $t) {
            $name = $t['artist']['#text'] ?? ($t['artist']['name'] ?? '');
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, array{name: string, artist: array{name: string}, playcount: int, image: array}>
     */
    private function getTrackCountsSince(int $sinceUnix): array
    {
        $counts = [];

        foreach ($this->getScrobblesSince($sinceUnix) as $t) {
            $name = $t['name'] ?? '';
            $artist = $t['artist']['#text'] ?? ($t['artist']['name'] ?? '');
            if ($name === '' || $artist === '') {
                continue;
            }

            $key = $artist . "\x01" . $name;
            if (!isset($counts[$key])) {
                $counts[$key] = ['name' => $name, 'artist' => ['name' => $artist], 'playcount' => 0, 'image' => $t['image'] ?? []];
            }
            $counts[$key]['playcount']++;
        }

        return $counts;
    }

    private function computeTopGenres(string $period, int $artistLimit, int $genreLimit, bool $onlySpotifyGenres = false): array
    {
        $top = $this->call('user.gettopartists', ['period' => $period, 'limit' => $artistLimit]);
        $artists = $top['topartists']['artist'] ?? [];

        if (isset($artists['name'])) {
            $artists = [$artists]; // API returns a single object (not an array) for exactly one artist
        }

        $artistPlaycounts = [];
        foreach ($artists as $artist) {
            $name = $artist['name'] ?? '';
            $playcount = (int) ($artist['playcount'] ?? 0);
            if ($name !== '' && $playcount > 0) {
                $artistPlaycounts[$name] = $playcount;
            }
        }

        return $this->genresFromScores($this->scoreGenreTags($artistPlaycounts, false, $onlySpotifyGenres), $genreLimit);
    }

    /**
     * @param array<string, int> $artistPlaycounts artist name => weight (playcount or scrobble count)
     * @param bool $cacheOnly Skip any artist whose tags aren't already cached rather than fetching live —
     *     used for the local-library "scan everything" path, which must never trigger a live-call burst
     *     from a page request; cron.php's LibrarySync::backfillArtistTags() fills the cache in instead.
     * @param bool $onlySpotifyGenres Drop any tag that isn't in Spotify's own genre vocabulary (see
     *     SPOTIFY_GENRE_SEEDS) — filters out Last.fm community-tag noise (artist names, list titles,
     *     one-off meta-tags) that GENRE_BLOCKLIST doesn't happen to cover.
     * @return array<string, int> tag name => aggregated score
     */
    public function scoreGenreTags(array $artistPlaycounts, bool $cacheOnly = false, bool $onlySpotifyGenres = false): array
    {
        $scores = [];

        foreach ($artistPlaycounts as $name => $playcount) {
            if ($name === '' || $playcount <= 0) {
                continue;
            }

            $tagsData = $this->call('artist.gettoptags', ['artist' => $name], 604800, $cacheOnly);
            $tags = $tagsData['toptags']['tag'] ?? [];

            if (isset($tags['name'])) {
                $tags = [$tags];
            }

            foreach (array_slice($tags, 0, 5) as $tag) {
                $tagName = strtolower(trim($tag['name'] ?? ''));
                if ($tagName === '' || in_array($tagName, self::GENRE_BLOCKLIST, true)) {
                    continue;
                }

                $tagName = self::GENRE_ALIASES[$tagName] ?? $tagName;

                if ($onlySpotifyGenres && !self::isKnownSpotifyGenre($tagName)) {
                    continue;
                }

                // Last.fm's tag "count" is a 0-100 relevance rank, not a play count.
                $weight = max(1, (int) ($tag['count'] ?? 0));
                $scores[$tagName] = ($scores[$tagName] ?? 0) + ($weight * $playcount);
            }
        }

        return $scores;
    }

    private static function isKnownSpotifyGenre(string $tagName): bool
    {
        static $seeds = null;
        if ($seeds === null) {
            $known = self::SPOTIFY_GENRE_SEEDS;
            $known[] = 'r&b'; // Spotify's own seed is "r-n-b"; this is the far more common tag spelling
            $seeds = array_flip(array_map(fn($g) => str_replace('-', ' ', $g), $known));
        }

        return isset($seeds[str_replace('-', ' ', $tagName)]);
    }

    /**
     * @param bool $includeOther Cap at $genreLimit and lump the rest into an "Other" slice — the
     *     right behaviour for a live, sampled artist list. Pass false to show every genre found
     *     with no cap and no "Other", appropriate once the local library has scanned every
     *     artist rather than a sample — $genreLimit is ignored in that case.
     */
    public static function genresFromScores(array $scores, int $genreLimit, bool $includeOther = true): array
    {
        arsort($scores);
        $total = array_sum($scores);

        if ($total <= 0) {
            return [];
        }

        $limit = $includeOther ? $genreLimit : count($scores);

        $result = [];
        foreach (array_slice($scores, 0, $limit, true) as $name => $score) {
            $result[] = [
                'name' => ucwords($name),
                'pct'  => round($score / $total * 100, 1),
            ];
        }

        if ($includeOther) {
            $shownTotal = array_sum(array_column($result, 'pct'));
            if (count($scores) > $genreLimit && $shownTotal < 99.5) {
                $result[] = ['name' => 'Other', 'pct' => round(100 - $shownTotal, 1)];
            }
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
