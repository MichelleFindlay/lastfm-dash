<?php

require_once __DIR__ . '/Http.php';

/**
 * Answers the Last.fm "user" API calls the app makes (user.getRecentTracks,
 * user.getTopTracks, user.getTopArtists, user.getInfo) from ListenBrainz or
 * Libre.fm instead, for setups with no Last.fm account. The responses are in
 * Last.fm's own JSON shape, so every widget, panel, Lifetime Stats and MCP
 * tool works unchanged. LastFm::call() hands these methods over here
 * whenever no Last.fm username is configured (see LastFm::setUserDelegate(),
 * wired up by LibrarySync::fromConfig()).
 *
 * Where answers come from, best first:
 *  1. The local library snapshot (lib/LibrarySync.php), once it covers the
 *     request. It's exact, merged across every configured service and
 *     de-duplicated.
 *  2. Otherwise the primary service's own API (ListenBrainz if configured,
 *     else Libre.fm). See ScrobbleSource::nativeUserCall().
 *
 * With no Last.fm API key either, artist.getTopTags (the genre tags behind
 * Genre Breakdown and Mood Weather) comes from MusicBrainz artist tags.
 * MusicBrainz allows about one request per second, so live lookups only
 * happen in cron.php's paced tag backfill (see allowSlowLookups()).
 * Everything else uses what's already cached, and genres fill in as cron
 * tags more artists. artist.getInfo (listener counts) and track.getInfo
 * (album, artwork, duration, global listeners) come from ListenBrainz's
 * public popularity data, keyed by the MusicBrainz artist ID that tag lookup
 * found, with Deezer as the fallback for track details.
 */
final class SourceUserApi
{
    private const MB_MIN_INTERVAL = 1.1;

    private static bool $slowLookups = false;

    private ScrobbleSource $primary;
    private LibrarySync $library;
    private string $cacheDir;

    public function __construct(ScrobbleSource $primary, LibrarySync $library)
    {
        $this->primary = $primary;
        $this->library = $library;
        $this->cacheDir = __DIR__ . '/../cache';
    }

    /**
     * Lets MusicBrainz tag lookups run live (paced at ~1/s). Only
     * LibrarySync::backfillArtistTags() turns this on, around each lookup
     * in its bounded per-run batch, so nothing else (a page request, or a
     * widget being pre-warmed in cron.php) can trigger hundreds of
     * one-per-second requests.
     */
    public static function allowSlowLookups(bool $allow = true): void
    {
        self::$slowLookups = $allow;
    }

    public function label(): string
    {
        return $this->primary->label();
    }

    /** Whether this delegate answers $method, given whether a Last.fm API key exists. */
    public function handles(string $method, bool $hasLastfmKey): bool
    {
        return strpos($method, 'user.') === 0
            || (!$hasLastfmKey && in_array($method, ['artist.gettoptags', 'artist.getinfo', 'track.getinfo'], true));
    }

    /** Same contract as LastFm::call(): a Last.fm-shaped array, or null for "no data". */
    public function call(string $method, array $params, int $ttl, bool $cacheOnly): ?array
    {
        unset($params['extended']); // no "loved" flags off Last.fm; same answer either way

        $cacheFile = $this->cacheDir . '/src_' . md5($this->primary->id() . $method . serialize($params)) . '.json';

        if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            // Tag entries cached before they carried the artist's MBID (needed
            // for listener counts) still serve genres, but cron's tag backfill
            // re-fetches them (see needsRefetch()).
            if (is_array($cached) && !(self::$slowLookups && self::needsRefetch($method, $cached))) {
                return $cached;
            }
        }

        if ($cacheOnly || ($method === 'artist.gettoptags' && !self::$slowLookups)) {
            return null;
        }

        try {
            $result = $this->compute($method, $params);
        } catch (Throwable $e) {
            $result = null;
        }

        if ($result !== null && $ttl > 0) {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0775, true);
            }
            @file_put_contents($cacheFile, json_encode($result));
        }

        return $result;
    }

    private function compute(string $method, array $params): ?array
    {
        switch ($method) {
            case 'user.getrecenttracks':
                return $this->recentTracks($params);
            case 'user.gettoptracks':
                return $this->localTop($params, false) ?? $this->primary->nativeUserCall($method, $params);
            case 'user.gettopartists':
                return $this->localTop($params, true) ?? $this->primary->nativeUserCall($method, $params);
            case 'user.getinfo':
                return $this->info();
            case 'artist.gettoptags':
                return $this->musicBrainzTags((string) ($params['artist'] ?? ''));
            case 'artist.getinfo':
                return $this->artistListeners((string) ($params['artist'] ?? ''));
            case 'track.getinfo':
                return $this->trackInfo((string) ($params['artist'] ?? ''), (string) ($params['track'] ?? ''));
            default:
                return $this->primary->nativeUserCall($method, $params);
        }
    }

    /** True for a MusicBrainz tag entry from before entries stored the artist's MBID. */
    public static function needsRefetch(string $method, array $cached): bool
    {
        return $method === 'artist.gettoptags' && !array_key_exists('mbid', $cached);
    }

    /** Your own play count for one track, from local history (track.getInfo's "userplaycount"). */
    public function userPlaycount(string $artist, string $track): int
    {
        return $this->library->playcountFor($artist, $track);
    }

    private static function periodSince(string $period): int
    {
        $days = ['7day' => 7, '1month' => 30, '3month' => 90, '6month' => 180, '12month' => 365][$period] ?? 0;

        return $days > 0 ? time() - $days * 86400 : 0;
    }

    private function recentTracks(array $params): ?array
    {
        $limit = max(1, min(1000, (int) ($params['limit'] ?? 50)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $from = isset($params['from']) ? (int) $params['from'] : null;
        $to = isset($params['to']) ? (int) $params['to'] : null;

        // "Since X" queries (today's scrobbles etc.): one page with all of them,
        // which is how every caller's pagination loop already ends.
        if ($from !== null) {
            $rows = $this->library->rawScrobbles($from, $to, 100000);
            if ($rows !== null) {
                return self::recentResponse(array_map(fn($r) => [$r['artist'], $r['track'], $r['timestamp']], $rows), 1, 1, count($rows));
            }

            return $this->primary->nativeUserCall('user.getrecenttracks', $params);
        }

        // Paged walk back through history: only Listening Clock / Energy
        // Curve do this, and they only need a sample of listening times, so
        // whatever has been imported so far will do (cron.php imports before
        // it warms widgets). The API walk is the last resort: on a big
        // ListenBrainz history it's hundreds of rate-limited requests.
        $all = $this->library->allScrobblesDesc(true);
        if ($all !== null) {
            if ($to !== null) {
                $all = array_values(array_filter($all, fn($s) => $s[2] <= $to));
            }
            $total = count($all);

            return self::recentResponse(array_slice($all, ($page - 1) * $limit, $limit), $page, max(1, (int) ceil($total / $limit)), $limit);
        }

        return $this->primary->nativeUserCall('user.getrecenttracks', $params);
    }

    /** @param array<int, array{0:string, 1:string, 2:int}> $scrobbles newest first */
    public static function recentResponse(array $scrobbles, int $page, int $totalPages, int $perPage): array
    {
        return ['recenttracks' => [
            'track' => array_map(fn($s) => ScrobbleSources::lastfmShaped($s[0], $s[1], '', $s[2]), $scrobbles),
            '@attr' => ['page' => (string) $page, 'perPage' => (string) $perPage, 'totalPages' => (string) $totalPages],
        ]];
    }

    private function localTop(array $params, bool $artists): ?array
    {
        $since = self::periodSince((string) ($params['period'] ?? 'overall'));
        $limit = max(1, (int) ($params['limit'] ?? 50));

        if ($artists) {
            $counts = $this->library->topArtistPlaycounts($since);
            if ($counts === null) {
                return null;
            }

            $list = [];
            $rank = 0;
            foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
                $list[] = ['name' => (string) $name, 'playcount' => (string) $count, 'image' => [], '@attr' => ['rank' => (string) ++$rank]];
            }

            return ['topartists' => ['artist' => $list]];
        }

        $tracks = $this->library->topTracks($since, $limit);
        if ($tracks === null) {
            return null;
        }

        return ['toptracks' => ['track' => array_map(function ($t) {
            $t['playcount'] = (string) $t['playcount'];
            return $t;
        }, $tracks)]];
    }

    /**
     * user.getInfo: playcount, artist/track counts and a "member since"
     * date, from local history (merged across services) as soon as anything
     * is imported, and the service's own live count before that. Counts it
     * can't provide are left out (shown as "—").
     */
    private function info(): ?array
    {
        $native = $this->primary->nativeUserCall('user.getinfo', []);
        $user = $native['user'] ?? ['name' => $this->primary->username()];

        // Local history merges every configured service, so it's used as
        // soon as anything is imported.
        $local = $this->library->localCounts(true);
        if ($local !== null) {
            // The service's live count can be ahead of local history (listens
            // just made, or history imported into it since; cron re-walks
            // for those), so the larger of the two.
            $user['playcount'] = (string) max((int) ($user['playcount'] ?? 0), $local['scrobbles']);
            $user['artist_count'] = (string) $local['artists'];
            $user['track_count'] = (string) $local['tracks'];
            $registered = (int) ($user['registered']['unixtime'] ?? 0);
            $user['registered'] = ['unixtime' => (string) ($registered > 0 ? min($registered, $local['first']) : $local['first'])];
        }

        return (int) ($user['playcount'] ?? 0) > 0 ? ['user' => $user] : null;
    }

    /**
     * Genre tags for an artist from MusicBrainz, in artist.getTopTags shape
     * (counts rescaled to Last.fm's 0-100 relevance range). Only a confident
     * name match counts; "no such artist" is a real (cacheable) empty answer,
     * while a failed or rate-limited request is null and retried later.
     */
    private function musicBrainzTags(string $artist): ?array
    {
        if ($artist === '') {
            return null;
        }

        $url = 'https://musicbrainz.org/ws/2/artist/?fmt=json&limit=3&query=' . rawurlencode('artist:"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $artist) . '"');
        $body = $this->throttledGet($url);
        $data = $body !== null ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['artists'])) {
            return null; // busy / rate-limited / unreachable
        }

        $match = null;
        foreach ($data['artists'] as $a) {
            if ((int) ($a['score'] ?? 0) >= 90 && strcasecmp(trim($a['name'] ?? ''), trim($artist)) === 0) {
                $match = $a;
                break;
            }
        }

        $tags = $match['tags'] ?? [];
        usort($tags, fn($x, $y) => (int) ($y['count'] ?? 0) <=> (int) ($x['count'] ?? 0));
        $max = max(1, (int) ($tags[0]['count'] ?? 1));

        return [
            'toptags' => ['tag' => array_map(fn($t) => [
                'name'  => (string) ($t['name'] ?? ''),
                'count' => (int) round(max(0, (int) ($t['count'] ?? 0)) / $max * 100),
            ], array_slice($tags, 0, 10))],
            'mbid' => (string) ($match['id'] ?? ''), // for artistListeners()
        ];
    }

    /**
     * artist.getInfo's listener count (all Before They Were Famous and
     * Obscurity Index need) from ListenBrainz's public popularity API: how
     * many ListenBrainz users have listened to the artist. Works for
     * Libre.fm-only setups too, as it needs no ListenBrainz account. It's
     * keyed by MusicBrainz artist ID, taken from the tag lookup cron already
     * made (cache only, so no MusicBrainz request here). Artists not tagged
     * yet return null and are skipped until they are.
     */
    private function artistListeners(string $artist): ?array
    {
        $tags = $this->call('artist.gettoptags', ['artist' => $artist], 604800, true);
        $mbid = (string) ($tags['mbid'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/', $mbid)) {
            return null;
        }

        self::paceListenBrainz();

        $body = Http::postJson('https://api.listenbrainz.org/1/popularity/artist', ['artist_mbids' => [$mbid]], ['User-Agent: lastfm-dash/1.0'], 10);
        $rows = $body !== null ? json_decode($body, true) : null;
        if (!is_array($rows) || isset($rows['error'])) {
            return null; // unreachable / rate-limited — retried next time
        }

        // No row, or null counts = ListenBrainz has no data for this artist:
        // a real (cacheable) answer of 0 listeners, which the widgets skip.
        $row = is_array($rows[0] ?? null) ? $rows[0] : [];

        return ['artist' => [
            'name'  => $artist,
            'mbid'  => $mbid,
            'stats' => [
                'listeners' => (string) (int) ($row['total_user_count'] ?? 0),
                'playcount' => (string) (int) ($row['total_listen_count'] ?? 0),
            ],
        ]];
    }

    /**
     * track.getInfo in Last.fm's shape: album, artwork, duration and global
     * listeners/plays. Your own play count and loved flag are added by
     * LastFm::getTrackStats() (local history and each service's loved list),
     * so they're not part of this cached answer.
     *
     * Best source first:
     *  1. ListenBrainz's per-artist recording popularity list (needs the
     *     artist's MusicBrainz ID from the tag cache): album, Cover Art
     *     Archive artwork, length, listener and play counts.
     *  2. Deezer search: album, artwork and duration (no listener counts).
     * "Not found anywhere" is a real, cacheable empty answer; null means a
     * request failed and the lookup is retried later.
     */
    private function trackInfo(string $artist, string $track): ?array
    {
        if ($artist === '' || $track === '') {
            return null;
        }

        $info = ['name' => $track, 'artist' => ['name' => $artist], 'listeners' => '0', 'playcount' => '0', 'duration' => '0', 'album' => ['title' => '', 'image' => []]];
        $failed = false;

        $tags = $this->call('artist.gettoptags', ['artist' => $artist], 604800, true);
        $mbid = (string) ($tags['mbid'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/', $mbid)) {
            $recordings = $this->artistRecordings($mbid);
            if ($recordings === null) {
                $failed = true;
            } else {
                $row = null;
                foreach (self::titleKeys($track) as $key) {
                    if (isset($recordings[$key])) {
                        $row = $recordings[$key];
                        break;
                    }
                }
                if ($row !== null) {
                    [$album, $image, $lengthMs, $users, $listens] = $row;
                    $info['album'] = ['title' => $album, 'image' => $image !== '' ? [['#text' => $image, 'size' => 'extralarge']] : []];
                    $info['duration'] = (string) $lengthMs;
                    $info['listeners'] = (string) $users;
                    $info['playcount'] = (string) $listens;
                }
            }
        }

        if ($info['album']['title'] === '' || empty($info['album']['image'])) {
            $deezer = $this->deezerTrack($artist, $track);
            if ($deezer === null) {
                $failed = true;
            } elseif (!empty($deezer)) {
                if ($info['album']['title'] === '') {
                    $info['album']['title'] = $deezer['album'];
                }
                if (empty($info['album']['image']) && $deezer['image'] !== '') {
                    $info['album']['image'] = [['#text' => $deezer['image'], 'size' => 'extralarge']];
                }
                if ((int) $info['duration'] === 0) {
                    $info['duration'] = (string) ($deezer['duration'] * 1000);
                }
            }
        }

        $found = $info['album']['title'] !== '' || !empty($info['album']['image']) || (int) $info['listeners'] > 0;

        return ($failed && !$found) ? null : ['track' => $info];
    }

    /**
     * Lookup keys for a track title, strictest first: as-is, then with a
     * trailing "(…)" / "[…]" / " - …" qualifier removed ("Creep - Remastered",
     * "Song (feat. X)"), so either spelling finds the other.
     *
     * @return string[]
     */
    private static function titleKeys(string $title): array
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower(trim($title), 'UTF-8') : strtolower(trim($title));
        $lower = preg_replace('/\s+/u', ' ', $lower) ?? $lower;
        $base = trim(preg_replace('/\s*(\([^)]*\)|\[[^\]]*\]|\s-\s.*)$/u', '', $lower) ?? $lower);

        return array_values(array_unique(array_filter([$lower, $base], fn($k) => $k !== '')));
    }

    /**
     * An artist's recordings from ListenBrainz's public popularity data,
     * as titleKey => [album, artwork URL, length ms, listeners, plays],
     * most-listened version first. The raw response can be over a megabyte
     * for a big artist, so only this compact map is cached (a week).
     *
     * @return array<string, array{0:string, 1:string, 2:int, 3:int, 4:int}>|null null on a failed request
     */
    private function artistRecordings(string $mbid): ?array
    {
        $file = $this->cacheDir . '/lbrec_' . md5($mbid) . '.json';
        if (is_file($file) && (time() - filemtime($file)) < 604800) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        self::paceListenBrainz();
        $body = Http::get('https://api.listenbrainz.org/1/popularity/top-recordings-for-artist/' . $mbid, ['User-Agent: lastfm-dash/1.0'], 15);
        $rows = $body !== null ? json_decode($body, true) : null;
        if (!is_array($rows) || isset($rows['error'])) {
            return null;
        }

        $map = [];
        foreach (array_slice($rows, 0, 1500) as $r) {
            $name = (string) ($r['recording_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $release = (string) ($r['caa_release_mbid'] ?? '');
            $caaId = $r['caa_id'] ?? null;
            $image = (preg_match('/^[0-9a-f-]{36}$/', $release) && is_numeric($caaId))
                ? 'https://coverartarchive.org/release/' . $release . '/' . $caaId . '-500.jpg'
                : '';
            $entry = [(string) ($r['release_name'] ?? ''), $image, (int) ($r['length'] ?? 0), (int) ($r['total_user_count'] ?? 0), (int) ($r['total_listen_count'] ?? 0)];
            foreach (self::titleKeys($name) as $key) {
                $map[$key] = $map[$key] ?? $entry;
            }
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($file, json_encode($map ?: new stdClass()));

        return $map;
    }

    /**
     * Album, artwork and duration (seconds) from Deezer's public search, for
     * the same artist (case-insensitive), preferring an exact title match.
     * [] when Deezer has no such track, null on a failed request.
     *
     * @return array{album: string, image: string, duration: int}|array{}|null
     */
    private function deezerTrack(string $artist, string $track): ?array
    {
        $body = Http::get('https://api.deezer.com/search?limit=10&q=' . rawurlencode($artist . ' ' . $track), ['User-Agent: lastfm-dash/1.0'], 8);
        $data = $body !== null ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return null;
        }

        $wanted = self::titleKeys($track);
        $best = null;
        foreach ($data['data'] as $r) {
            if (strcasecmp(trim((string) ($r['artist']['name'] ?? '')), trim($artist)) !== 0) {
                continue;
            }
            if (array_intersect(self::titleKeys((string) ($r['title'] ?? '')), $wanted)) {
                $best = $r;
                break;
            }
            $best = $best ?? $r;
        }

        if ($best === null) {
            return [];
        }

        return [
            'album'    => (string) ($best['album']['title'] ?? ''),
            'image'    => (string) ($best['album']['cover_big'] ?? ($best['album']['cover_medium'] ?? '')),
            'duration' => (int) ($best['duration'] ?? 0),
        ];
    }

    /** ~3 ListenBrainz requests/s at most, under its ~30 per 10 s limit. */
    private static function paceListenBrainz(): void
    {
        static $last = 0.0;
        $wait = $last + 0.35 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $last = microtime(true);
    }

    /** Shares FilmSoundtracks' lock file, so every MusicBrainz request on this host is paced together. */
    private function throttledGet(string $url): ?string
    {
        $versionFile = __DIR__ . '/../VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '1.0';
        $headers = ['User-Agent: lastfm-dash/' . $version . ' ( https://github.com/MichelleFindlay/lastfm-dash )', 'Accept: application/json'];

        $lock = @fopen($this->cacheDir . '/musicbrainz.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return Http::get($url, $headers, 10);
        }

        $last = (float) stream_get_contents($lock);
        $wait = $last + self::MB_MIN_INTERVAL - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }

        try {
            return Http::get($url, $headers, 10);
        } finally {
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string) microtime(true));
            fflush($lock);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
