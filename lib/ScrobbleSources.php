<?php

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/LastFm.php';
require_once __DIR__ . '/SourceUserApi.php';

/**
 * Extra listening-history services besides Last.fm: ListenBrainz and
 * Libre.fm. Each one can do two jobs, both optional and both enabled just by
 * setting that service's username in config.php:
 *
 *  - Import: cron.php pulls its full history into the local library snapshot
 *    (lib/LibrarySync.php) in bounded batches, the same way the Last.fm
 *    backfill works, de-duplicated against what's already there. That way
 *    anyone who scrobbles to more than one service doesn't get their plays
 *    counted twice.
 *  - Live listening: the Now Playing card (api.php / index.php / the MCP
 *    get_now_playing tool) also checks this service. When Last.fm itself
 *    isn't currently "now scrobbling" but this service is, that track is
 *    shown instead. "Last played" also picks whichever service heard the
 *    newest scrobble.
 *
 * Either can also be used on its own with no Last.fm account at all (see
 * lib/SourceUserApi.php, which stands in for every Last.fm call the app
 * makes). Tracks loved on either service show the loved heart too.
 *
 * Scrobbles are passed around in the same compact [artist, track, unix
 * timestamp] form LibrarySync stores. "Recent track" lists use Last.fm's own
 * user.getRecentTracks item shape, so the existing rendering code (and
 * LastFm::findPreviousTrack()) works on them unchanged.
 */
interface ScrobbleSource
{
    /** Stable key for this account's import state, e.g. "listenbrainz:rob". */
    public function id(): string;

    /** Human-readable service name, e.g. "ListenBrainz". */
    public function label(): string;

    /** The configured username on this service. */
    public function username(): string;

    /** Public profile page for that user. */
    public function profileUrl(): string;

    /**
     * Up to $maxPages pages of scrobbles at or before $upToTs, newest
     * first. Throws on an API/network error, so a failed run is never
     * mistaken for "reached the start of history".
     *
     * @return array{scrobbles: array<int, array{0:string, 1:string, 2:int}>, pages: int, end: bool}
     */
    public function fetchBefore(int $upToTs, int $maxPages): array;

    /**
     * Every scrobble strictly after $sinceTs (bounded by a safety cap).
     * Throws on an API/network error.
     *
     * @return array<int, array{0:string, 1:string, 2:int}>
     */
    public function fetchSince(int $sinceTs): array;

    /**
     * The most recent $limit tracks in Last.fm getRecentTracks item shape, a
     * now-playing entry (if any) first. Never throws: returns [] on error, so
     * an outage on this service can't break the Now Playing card.
     */
    public function recentTracks(int $limit, int $ttl): array;

    /**
     * Answers a Last.fm "user.*" method (user.getrecenttracks /
     * gettoptracks / gettopartists / getinfo) from this service's own API,
     * in Last.fm's response shape. Used by SourceUserApi when there's no
     * Last.fm account and local history doesn't cover the request yet.
     * Null if unsupported or unavailable.
     */
    public function nativeUserCall(string $method, array $params): ?array;

    /**
     * Every track this user has loved/hearted on this service, as
     * [artist, track] pairs. Throws on an API/network error (so a failure
     * isn't cached as "nothing loved"); see ScrobbleSources::lovedKeys().
     *
     * @return array<int, array{0:string, 1:string}>
     */
    public function lovedTracks(): array;
}

final class ScrobbleSources
{
    /**
     * Two scrobbles of the same artist + track from different services
     * count as the same play if their timestamps fall within this many
     * seconds of each other. Matching is one-to-one, nearest-first, so a
     * track genuinely played twice in a row still counts twice as long as
     * both services recorded both plays. Overridable via
     * 'import_dedupe_seconds' in config.php.
     */
    public const DEFAULT_DEDUPE_SECONDS = 180;

    public const LASTFM_ID = 'lastfm';

    /**
     * @return ScrobbleSource[] in config order (ListenBrainz, then Libre.fm)
     */
    public static function fromConfig(array $config): array
    {
        $sources = [];

        $lbUser = trim((string) ($config['listenbrainz_username'] ?? ''));
        if ($lbUser !== '') {
            $sources[] = new ListenBrainzSource($lbUser, trim((string) ($config['listenbrainz_token'] ?? '')));
        }

        $libreUser = trim((string) ($config['librefm_username'] ?? ''));
        if ($libreUser !== '') {
            $sources[] = new LibreFmSource($libreUser);
        }

        return $sources;
    }

    public static function dedupeSeconds(array $config): int
    {
        return max(0, (int) ($config['import_dedupe_seconds'] ?? self::DEFAULT_DEDUPE_SECONDS));
    }

    /** Case- and whitespace-insensitive artist + track key for cross-service matching. */
    public static function matchKey(string $artist, string $track): string
    {
        $lower = function_exists('mb_strtolower')
            ? fn(string $s) => mb_strtolower($s, 'UTF-8')
            : fn(string $s) => strtolower($s);

        $norm = fn(string $s) => preg_replace('/\s+/u', ' ', trim($lower($s))) ?? trim($lower($s));

        return $norm($artist) . "\x01" . $norm($track);
    }

    /**
     * Combines Last.fm's own recent-tracks list with every configured
     * source's. The result is one Last.fm-shaped list, newest first, with
     * the live "now playing" entry (if any service has one) on top. Last.fm
     * wins a tie between two services both reporting something playing
     * right now, then the other sources in config order. A play that two
     * services both recorded shows up once. Every entry gets a `_source` key
     * (a label like "ListenBrainz") so the UI can say where it came from.
     *
     * @param array $lastfmTracks Raw `recenttracks.track` from Last.fm (list or single object).
     * @param ScrobbleSource[] $sources
     */
    public static function mergeRecent(array $lastfmTracks, array $sources, int $limit, int $ttl, int $dedupeSeconds = self::DEFAULT_DEDUPE_SECONDS): array
    {
        if (isset($lastfmTracks['name'])) {
            $lastfmTracks = [$lastfmTracks];
        }

        foreach ($lastfmTracks as &$t) {
            $t['_source'] = 'Last.fm';
        }
        unset($t);

        if (empty($sources)) {
            return $lastfmTracks; // nothing to merge — keep Last.fm's list exactly as-is
        }

        $lists = [$lastfmTracks];
        foreach ($sources as $source) {
            $list = $source->recentTracks($limit, $ttl);
            foreach ($list as &$t) {
                $t['_source'] = $source->label();
            }
            unset($t);
            $lists[] = $list;
        }

        $nowPlaying = null;
        $dated = [];
        foreach ($lists as $list) {
            foreach ($list as $t) {
                if (($t['@attr']['nowplaying'] ?? '') === 'true') {
                    $nowPlaying = $nowPlaying ?? $t; // first list (Last.fm) wins
                } elseif (isset($t['date']['uts'])) {
                    $dated[] = $t;
                }
            }
        }

        usort($dated, fn($a, $b) => (int) $b['date']['uts'] <=> (int) $a['date']['uts']);

        // Drop a play that a second service also recorded. The first copy
        // seen wins: newest timestamp, and Last.fm's on a tie, since its
        // entry carries extras (loved, image) the others don't have.
        $kept = [];
        foreach ($dated as $t) {
            $key = self::matchKey(self::artistOf($t), (string) ($t['name'] ?? ''));
            $ts = (int) $t['date']['uts'];
            $dup = false;
            foreach ($kept as $k) {
                if ($k['_source'] !== $t['_source'] && $k['_key'] === $key && abs($k['_ts'] - $ts) <= $dedupeSeconds) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $t['_key'] = $key;
                $t['_ts'] = $ts;
                $kept[] = $t;
            }
        }

        $kept = array_map(function ($t) {
            unset($t['_key'], $t['_ts']);
            return $t;
        }, $kept);

        $merged = $nowPlaying ? array_merge([$nowPlaying], $kept) : $kept;

        return self::markLoved(array_slice($merged, 0, max(1, $limit)), $sources);
    }

    /**
     * The tracks loved on any of $sources, as a set of matchKey()s, for the
     * loved hearts. Each service's list is cached for an hour; one that
     * fails keeps its last good list (or counts as none) without breaking
     * the page.
     *
     * @param ScrobbleSource[] $sources
     * @return array<string, true>
     */
    public static function lovedKeys(array $sources): array
    {
        static $memo = [];

        $keys = [];
        foreach ($sources as $source) {
            $id = $source->id();
            if (!isset($memo[$id])) {
                $memo[$id] = self::cachedLoved($source);
            }
            $keys += $memo[$id];
        }

        return $keys;
    }

    /** @return array<string, true> */
    private static function cachedLoved(ScrobbleSource $source): array
    {
        $file = __DIR__ . '/../cache/loved_' . md5($source->id()) . '.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (is_array($cached) && (time() - filemtime($file)) < 3600) {
            return $cached;
        }

        try {
            $keys = [];
            foreach ($source->lovedTracks() as [$artist, $track]) {
                $keys[self::matchKey($artist, $track)] = true;
            }
            @file_put_contents($file, json_encode($keys ?: new stdClass()));

            return $keys;
        } catch (Throwable $e) {
            if (is_file($file)) {
                @touch($file); // retry in an hour, not on every request
            }

            return is_array($cached) ? $cached : [];
        }
    }

    /** Sets 'loved' on each recent-track item that any source has loved (Last.fm's own flag is kept). */
    public static function markLoved(array $tracks, array $sources): array
    {
        if (empty($sources)) {
            return $tracks;
        }

        $loved = self::lovedKeys($sources);
        foreach ($tracks as &$t) {
            if (isset($loved[self::matchKey(self::artistOf($t), (string) ($t['name'] ?? ''))])) {
                $t['loved'] = '1';
            }
        }
        unset($t);

        return $tracks;
    }

    public static function artistOf(array $t): string
    {
        return (string) ($t['artist']['#text'] ?? ($t['artist']['name'] ?? ''));
    }

    /** A Last.fm getRecentTracks-shaped item, for services that don't speak that format themselves. */
    public static function lastfmShaped(string $artist, string $track, string $album, ?int $ts, string $url = '', string $image = ''): array
    {
        $item = [
            'artist' => ['#text' => $artist],
            'name'   => $track,
            'album'  => ['#text' => $album],
            'image'  => $image !== '' ? [['#text' => $image, 'size' => 'extralarge']] : [],
            'url'    => $url,
        ];

        if ($ts === null) {
            $item['@attr'] = ['nowplaying' => 'true'];
        } else {
            $item['date'] = ['uts' => (string) $ts, '#text' => gmdate('j M Y, H:i', $ts)];
        }

        return $item;
    }
}

/**
 * ListenBrainz (https://listenbrainz.org). Listen history is public, so only
 * a username is needed. An optional user token (from
 * https://listenbrainz.org/settings/) is sent if configured, which also
 * covers accounts with private listens. The token is never logged or
 * exposed to the browser.
 *
 * API: https://listenbrainz.readthedocs.io/en/latest/users/api/core.html
 * Rate limit is ~30 requests per 10 seconds per IP; a 429 surfaces as an
 * error, and cron.php just resumes on its next run.
 */
final class ListenBrainzSource implements ScrobbleSource
{
    private const API = 'https://api.listenbrainz.org/1';
    private const PAGE_SIZE = 1000; // ListenBrainz's own per-request maximum
    private const LIVE_TIMEOUT = 4;

    private string $user;
    private string $token;

    public function __construct(string $user, string $token = '')
    {
        $this->user = $user;
        $this->token = $token;
    }

    public function id(): string
    {
        return 'listenbrainz:' . strtolower($this->user);
    }

    public function label(): string
    {
        return 'ListenBrainz';
    }

    public function username(): string
    {
        return $this->user;
    }

    public function profileUrl(): string
    {
        return 'https://listenbrainz.org/user/' . rawurlencode($this->user) . '/';
    }

    private function headers(): array
    {
        $headers = ['User-Agent: lastfm-dash/1.0'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Token ' . $this->token;
        }

        return $headers;
    }

    private function get(string $path, array $query, int $timeout = 15): array
    {
        // Stay under ListenBrainz's ~30 requests / 10 s per IP during long
        // walks (backfill, a cold Listening Clock): at most ~3 requests/s.
        static $last = 0.0;
        $wait = $last + 0.35 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $last = microtime(true);

        $url = self::API . '/user/' . rawurlencode($this->user) . '/' . $path . ($query ? '?' . http_build_query($query) : '');
        $body = Http::get($url, $this->headers(), $timeout);
        $data = $body !== null ? json_decode($body, true) : null;

        if (!is_array($data) || !isset($data['payload']) || !is_array($data['payload'])) {
            $reason = is_array($data) ? ($data['error'] ?? 'unexpected response') : 'no response';
            throw new RuntimeException('ListenBrainz: ' . $reason);
        }

        return $data['payload'];
    }

    /** @return array{0:string, 1:string, 2:int}|null */
    private static function toScrobble(array $listen): ?array
    {
        $meta = $listen['track_metadata'] ?? [];
        $artist = trim((string) ($meta['artist_name'] ?? ''));
        $track = trim((string) ($meta['track_name'] ?? ''));
        $ts = $listen['listened_at'] ?? null;

        if ($artist === '' || $track === '' || !is_numeric($ts)) {
            return null;
        }

        return [$artist, $track, (int) $ts];
    }

    public function fetchBefore(int $upToTs, int $maxPages): array
    {
        $scrobbles = [];
        $pages = 0;
        $end = false;
        $maxTs = $upToTs + 1; // ListenBrainz's max_ts is exclusive

        for ($i = 0; $i < $maxPages; $i++) {
            $payload = $this->get('listens', ['max_ts' => $maxTs, 'count' => self::PAGE_SIZE]);
            $listens = $payload['listens'] ?? [];
            $pages++;

            $oldest = $maxTs;
            foreach ($listens as $l) {
                $s = self::toScrobble($l);
                if ($s !== null) {
                    $scrobbles[] = $s;
                }
                if (is_numeric($l['listened_at'] ?? null)) {
                    $oldest = min($oldest, (int) $l['listened_at']);
                }
            }

            // Short page = start of history. Also stop if the cursor didn't
            // move, so a malformed response can't loop forever.
            if (count($listens) < self::PAGE_SIZE || $oldest >= $maxTs) {
                $end = true;
                break;
            }

            $maxTs = $oldest;
        }

        return ['scrobbles' => $scrobbles, 'pages' => $pages, 'end' => $end];
    }

    public function fetchSince(int $sinceTs): array
    {
        $scrobbles = [];
        $minTs = $sinceTs; // ListenBrainz's min_ts is exclusive

        // With only min_ts, ListenBrainz returns the listens closest after
        // it, so walking forward page by page can't skip a gap. Capped at 10
        // pages (10,000 listens) per run as a safety limit.
        for ($i = 0; $i < 10; $i++) {
            $payload = $this->get('listens', ['min_ts' => $minTs, 'count' => self::PAGE_SIZE]);
            $listens = $payload['listens'] ?? [];

            $newest = $minTs;
            foreach ($listens as $l) {
                $s = self::toScrobble($l);
                if ($s !== null && $s[2] > $sinceTs) {
                    $scrobbles[] = $s;
                }
                if (is_numeric($l['listened_at'] ?? null)) {
                    $newest = max($newest, (int) $l['listened_at']);
                }
            }

            if (count($listens) < self::PAGE_SIZE || $newest <= $minTs) {
                break;
            }

            $minTs = $newest;
        }

        return $scrobbles;
    }

    public function recentTracks(int $limit, int $ttl): array
    {
        $tracks = [];

        try {
            $playing = $this->cachedGet('playing-now', [], $ttl);
            $listen = $playing['listens'][0] ?? null;
            if ($listen && !empty($playing['playing_now'])) {
                $meta = $listen['track_metadata'] ?? [];
                $artist = trim((string) ($meta['artist_name'] ?? ''));
                $track = trim((string) ($meta['track_name'] ?? ''));
                if ($artist !== '' && $track !== '') {
                    $tracks[] = ScrobbleSources::lastfmShaped($artist, $track, (string) ($meta['release_name'] ?? ''), null, '', self::coverArt($listen));
                }
            }

            $recent = $this->cachedGet('listens', ['count' => max(1, $limit)], $ttl);
            foreach ($recent['listens'] ?? [] as $l) {
                $s = self::toScrobble($l);
                if ($s !== null) {
                    $tracks[] = ScrobbleSources::lastfmShaped($s[0], $s[1], (string) ($l['track_metadata']['release_name'] ?? ''), $s[2], '', self::coverArt($l));
                }
            }
        } catch (Throwable $e) {
            // Live view degrades to whatever we already have — never breaks the card.
        }

        return $tracks;
    }

    /**
     * Loved tracks are ListenBrainz "feedback" with score 1. That endpoint
     * answers at the top level rather than under "payload", so it doesn't
     * go through get(). Capped at 10 pages (10,000 loved tracks).
     */
    public function lovedTracks(): array
    {
        $loved = [];

        for ($offset = 0, $page = 0; $page < 10; $page++, $offset += self::PAGE_SIZE) {
            $url = self::API . '/feedback/user/' . rawurlencode($this->user) . '/get-feedback?'
                . http_build_query(['score' => 1, 'count' => self::PAGE_SIZE, 'offset' => $offset, 'metadata' => 'true']);
            $body = Http::get($url, $this->headers(), 10);
            $data = $body !== null ? json_decode($body, true) : null;
            if (!is_array($data) || !isset($data['feedback']) || !is_array($data['feedback'])) {
                throw new RuntimeException('ListenBrainz: loved tracks unavailable');
            }

            foreach ($data['feedback'] as $f) {
                $artist = trim((string) ($f['track_metadata']['artist_name'] ?? ''));
                $track = trim((string) ($f['track_metadata']['track_name'] ?? ''));
                if ($artist !== '' && $track !== '') {
                    $loved[] = [$artist, $track];
                }
            }

            if (count($data['feedback']) < self::PAGE_SIZE) {
                break;
            }
        }

        return $loved;
    }

    /**
     * Album art from the Cover Art Archive, when ListenBrainz has matched the
     * listen to a MusicBrainz release that has art (most do). Empty
     * otherwise, which sends the card to the usual Spotify/Apple Music
     * fallback.
     */
    private static function coverArt(array $listen): string
    {
        $map = $listen['track_metadata']['mbid_mapping'] ?? [];
        $release = (string) ($map['caa_release_mbid'] ?? '');
        $caaId = $map['caa_id'] ?? null;

        if ($release === '' || !is_numeric($caaId) || !preg_match('/^[0-9a-f-]{36}$/', $release)) {
            return '';
        }

        return 'https://coverartarchive.org/release/' . $release . '/' . $caaId . '-500.jpg';
    }

    public function nativeUserCall(string $method, array $params): ?array
    {
        switch ($method) {
            case 'user.getrecenttracks':
                return $this->nativeRecent($params);
            case 'user.gettoptracks':
            case 'user.gettopartists':
                return $this->nativeTop($method === 'user.gettopartists', (string) ($params['period'] ?? 'overall'), max(1, (int) ($params['limit'] ?? 50)));
            case 'user.getinfo':
                $count = (int) ($this->cachedGet('listen-count', [], 300)['count'] ?? 0);
                $user = ['name' => $this->user, 'playcount' => (string) $count, 'url' => $this->profileUrl()];

                // Album total: only ListenBrainz's statistics know it (listens
                // don't carry album IDs we could count). Those stats are
                // recalculated periodically, so this can trail a little.
                $releases = $this->statsTotal('releases', 'total_release_count');
                if ($releases !== null) {
                    $user['album_count'] = (string) $releases;
                }

                return ['user' => $user];
            default:
                return null;
        }
    }

    private function nativeRecent(array $params): array
    {
        // Big paged walks (callers asking 200 a page) get ListenBrainz's
        // maximum of 1,000 instead: callers just follow totalPages, so it's
        // the same data in a fifth of the requests.
        $requested = (int) ($params['limit'] ?? 50);
        $limit = $requested >= 200 ? self::PAGE_SIZE : max(1, $requested);
        $page = max(1, (int) ($params['page'] ?? 1));
        $to = isset($params['to']) ? (int) $params['to'] : null;

        if (isset($params['from'])) {
            $rows = array_values(array_filter($this->fetchSince((int) $params['from'] - 1), fn($s) => $to === null || $s[2] <= $to));
            usort($rows, fn($a, $b) => $b[2] <=> $a[2]);

            return SourceUserApi::recentResponse($rows, 1, 1, count($rows));
        }

        // ListenBrainz pages by timestamp, not page number, so page N needs
        // page N-1's oldest timestamp. Those cursors are cached, so a caller
        // walking pages 1, 2, 3... costs one request per page.
        $cursorFile = __DIR__ . '/../cache/lb_cursor_' . md5($this->id() . '|' . $limit . '|' . ($to ?? 'latest')) . '.json';
        $cursors = [];
        if (is_file($cursorFile) && (time() - filemtime($cursorFile)) < ($to === null ? 3600 : 2592000)) {
            $cursors = json_decode((string) file_get_contents($cursorFile), true) ?: [];
        }
        $cursors[1] = $to !== null ? $to + 1 : 0; // 0 = newest

        $known = array_filter(array_keys($cursors), fn($p) => $p <= $page);
        $p = (int) max($known);
        $listens = [];

        for ($hops = 0; $hops < 50; $hops++) {
            $query = ['count' => $limit];
            if ((int) $cursors[$p] > 0) {
                $query['max_ts'] = (int) $cursors[$p];
            }
            $listens = $this->get('listens', $query)['listens'] ?? [];

            if ($p === $page) {
                break;
            }
            if (count($listens) < $limit) {
                $listens = []; // asked for a page past the end
                break;
            }

            $oldest = min(array_map(fn($l) => (int) ($l['listened_at'] ?? PHP_INT_MAX), $listens));
            $cursors[++$p] = $oldest;
        }
        if ($p !== $page) {
            $listens = [];
        }

        @file_put_contents($cursorFile, json_encode($cursors));

        $rows = array_values(array_filter(array_map(fn($l) => self::toScrobble($l), $listens)));
        if ($to === null) {
            $total = (int) ($this->cachedGet('listen-count', [], 300)['count'] ?? 0);
            $totalPages = max(1, (int) ceil($total / $limit));
        } else {
            $totalPages = count($listens) === $limit ? $page + 1 : $page;
        }

        return SourceUserApi::recentResponse($rows, $page, $totalPages, $limit);
    }

    /**
     * One all-time total from ListenBrainz's statistics API (e.g.
     * total_release_count), cached a day. Null if stats aren't available
     * yet (ListenBrainz answers 204 for a user with no stats).
     */
    private function statsTotal(string $type, string $field): ?int
    {
        $cacheFile = __DIR__ . '/../cache/lb_stats_' . md5($this->id() . $type . $field) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (isset($cached['value'])) {
                return (int) $cached['value'];
            }
        }

        $url = self::API . '/stats/user/' . rawurlencode($this->user) . '/' . $type . '?' . http_build_query(['range' => 'all_time', 'count' => 1]);
        $body = Http::get($url, $this->headers(), 10);
        $value = $body !== null ? (json_decode($body, true)['payload'][$field] ?? null) : null;

        if (!is_numeric($value)) {
            return null;
        }

        @file_put_contents($cacheFile, json_encode(['value' => (int) $value]));

        return (int) $value;
    }

    /**
     * Top artists/tracks from ListenBrainz's statistics API. Its stats are
     * recalculated periodically rather than live, so this is only the
     * fallback while local history is still importing.
     */
    private function nativeTop(bool $artists, string $period, int $limit): array
    {
        $range = ['7day' => 'this_week', '1month' => 'this_month', '3month' => 'quarter', '6month' => 'half_yearly', '12month' => 'this_year'][$period] ?? 'all_time';
        $url = self::API . '/stats/user/' . rawurlencode($this->user) . '/' . ($artists ? 'artists' : 'recordings') . '?' . http_build_query(['range' => $range, 'count' => min(1000, $limit)]);
        $body = Http::get($url, $this->headers(), 15);
        $payload = $body !== null ? (json_decode($body, true)['payload'] ?? []) : []; // 204 = no stats yet

        if ($artists) {
            $list = [];
            foreach ($payload['artists'] ?? [] as $i => $a) {
                $list[] = ['name' => (string) ($a['artist_name'] ?? ''), 'playcount' => (string) ($a['listen_count'] ?? 0), 'image' => [], '@attr' => ['rank' => (string) ($i + 1)]];
            }

            return ['topartists' => ['artist' => $list]];
        }

        $list = [];
        foreach ($payload['recordings'] ?? [] as $r) {
            $list[] = ['name' => (string) ($r['track_name'] ?? ''), 'artist' => ['name' => (string) ($r['artist_name'] ?? '')], 'playcount' => (string) ($r['listen_count'] ?? 0), 'image' => []];
        }

        return ['toptracks' => ['track' => $list]];
    }

    /**
     * Short-lived file cache for the live calls (same cache/ directory as
     * everything else), so a burst of near-simultaneous page polls makes one
     * request, not one each. Only successful responses are cached.
     */
    private function cachedGet(string $path, array $query, int $ttl): array
    {
        $cacheFile = __DIR__ . '/../cache/lb_' . md5($this->id() . $path . serialize($query) . ($this->token !== '' ? '1' : '0')) . '.json';

        if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = $this->get($path, $query, self::LIVE_TIMEOUT);

        if ($ttl > 0) {
            @file_put_contents($cacheFile, json_encode($payload));
        }

        return $payload;
    }
}

/**
 * Libre.fm (https://libre.fm), which runs GNU FM: a Last.fm-compatible 2.0
 * API, so this is the existing LastFm client pointed at libre.fm/2.0/.
 * Public profiles need only a username. GNU FM doesn't check the api_key
 * parameter, so a fixed placeholder is sent. Your real Last.fm key is never
 * sent to a third party.
 *
 * One quirk vs Last.fm: user.getRecentTracks honours `to` but ignores
 * `from`, so fetchSince() walks pages newest-first and stops at the last
 * synced timestamp itself.
 */
final class LibreFmSource implements ScrobbleSource
{
    public const API_BASE = 'https://libre.fm/2.0/';
    private const PAGE_SIZE = 200;

    private string $user;
    private LastFm $client;

    public function __construct(string $user)
    {
        $this->user = $user;
        $this->client = new LastFm('lastfm-dash', $user, 60, self::API_BASE);
    }

    public function id(): string
    {
        return 'librefm:' . strtolower($this->user);
    }

    public function label(): string
    {
        return 'Libre.fm';
    }

    public function username(): string
    {
        return $this->user;
    }

    public function profileUrl(): string
    {
        return 'https://libre.fm/user/' . rawurlencode($this->user);
    }

    /** @return array{tracks: array, totalPages: int} */
    private function page(array $params, int $ttl): array
    {
        $data = $this->client->call('user.getrecenttracks', $params, $ttl);
        if ($data === null || !isset($data['recenttracks'])) {
            throw new RuntimeException('Libre.fm: no response or unknown user');
        }

        $tracks = $data['recenttracks']['track'] ?? [];
        if (isset($tracks['name'])) {
            $tracks = [$tracks];
        }

        return ['tracks' => is_array($tracks) ? $tracks : [], 'totalPages' => (int) ($data['recenttracks']['@attr']['totalPages'] ?? 1)];
    }

    /** @return array{0:string, 1:string, 2:int}|null */
    private static function toScrobble(array $t): ?array
    {
        $uts = $t['date']['uts'] ?? null;
        $name = trim((string) ($t['name'] ?? ''));
        $artist = trim(ScrobbleSources::artistOf($t));

        if (!is_numeric($uts) || $name === '' || $artist === '') {
            return null;
        }

        return [$artist, $name, (int) $uts];
    }

    public function fetchBefore(int $upToTs, int $maxPages): array
    {
        // Same fixed-anchor page walk as LibrarySync's Last.fm backfill: `to`
        // stays put for the whole batch so new scrobbles can't shift pages.
        $scrobbles = [];
        $pages = 0;
        $end = false;

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->page(['limit' => self::PAGE_SIZE, 'page' => $page, 'to' => $upToTs], 2592000);
            if (empty($result['tracks'])) {
                $end = true;
                break;
            }

            $pages++;
            foreach ($result['tracks'] as $t) {
                $s = self::toScrobble($t);
                if ($s !== null && $s[2] <= $upToTs) {
                    $scrobbles[] = $s;
                }
            }

            if ($page >= $result['totalPages']) {
                $end = true;
                break;
            }
        }

        return ['scrobbles' => $scrobbles, 'pages' => $pages, 'end' => $end];
    }

    public function fetchSince(int $sinceTs): array
    {
        $scrobbles = [];

        for ($page = 1; $page <= 10; $page++) {
            $result = $this->page(['limit' => self::PAGE_SIZE, 'page' => $page, 'from' => $sinceTs + 1], 60);

            $reachedOld = false;
            foreach ($result['tracks'] as $t) {
                $s = self::toScrobble($t);
                if ($s === null) {
                    continue; // now-playing entry, or incomplete
                }
                if ($s[2] <= $sinceTs) {
                    $reachedOld = true;
                    continue;
                }
                $scrobbles[] = $s;
            }

            if ($reachedOld || empty($result['tracks']) || $page >= $result['totalPages']) {
                break;
            }
        }

        return $scrobbles;
    }

    public function nativeUserCall(string $method, array $params): ?array
    {
        // GNU FM ignores `from`, so "since X" queries are walked and filtered here.
        if ($method === 'user.getrecenttracks' && isset($params['from'])) {
            $to = isset($params['to']) ? (int) $params['to'] : null;
            $rows = array_values(array_filter($this->fetchSince((int) $params['from'] - 1), fn($s) => $to === null || $s[2] <= $to));
            usort($rows, fn($a, $b) => $b[2] <=> $a[2]);

            return SourceUserApi::recentResponse($rows, 1, 1, count($rows));
        }

        $data = $this->client->call($method, $params, 300);

        // GNU FM's user.getInfo has "profile_created" instead of Last.fm's registered.unixtime.
        if ($method === 'user.getinfo' && isset($data['user']) && !isset($data['user']['registered']['unixtime'])) {
            $created = strtotime((string) ($data['user']['profile_created'] ?? ''));
            if ($created !== false && $created > 0) {
                $data['user']['registered'] = ['unixtime' => (string) $created];
            }
        }

        return $data;
    }

    public function lovedTracks(): array
    {
        $loved = [];

        for ($page = 1; $page <= 10; $page++) {
            $data = $this->client->call('user.getlovedtracks', ['limit' => 500, 'page' => $page], 3600);
            if ($data === null || !isset($data['lovedtracks'])) {
                throw new RuntimeException('Libre.fm: loved tracks unavailable');
            }

            $tracks = $data['lovedtracks']['track'] ?? [];
            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }
            foreach (is_array($tracks) ? $tracks : [] as $t) {
                $artist = trim(ScrobbleSources::artistOf($t));
                $name = trim((string) ($t['name'] ?? ''));
                if ($artist !== '' && $name !== '') {
                    $loved[] = [$artist, $name];
                }
            }

            if (empty($tracks) || $page >= (int) ($data['lovedtracks']['@attr']['totalPages'] ?? 1)) {
                break;
            }
        }

        return $loved;
    }

    public function recentTracks(int $limit, int $ttl): array
    {
        try {
            $result = $this->page(['limit' => max(1, $limit)], max(1, $ttl));
        } catch (Throwable $e) {
            return [];
        }

        // Already Last.fm-shaped. Libre.fm rarely includes artwork, and an
        // empty image list just sends the card to the usual Last.fm/Spotify/
        // Apple Music art fallback.
        return $result['tracks'];
    }
}
