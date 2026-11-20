<?php

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/LastFm.php';

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
 * Last.fm stays the primary account: track metadata, tags, album art,
 * lifetime stats and the live fallbacks still come from it. These services
 * only add listens.
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

        return array_slice($merged, 0, max(1, $limit));
    }

    public static function artistOf(array $t): string
    {
        return (string) ($t['artist']['#text'] ?? ($t['artist']['name'] ?? ''));
    }

    /** A Last.fm getRecentTracks-shaped item, for services that don't speak that format themselves. */
    public static function lastfmShaped(string $artist, string $track, string $album, ?int $ts, string $url = ''): array
    {
        $item = [
            'artist' => ['#text' => $artist],
            'name'   => $track,
            'album'  => ['#text' => $album],
            'image'  => [],
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
                    $tracks[] = ScrobbleSources::lastfmShaped($artist, $track, (string) ($meta['release_name'] ?? ''), null);
                }
            }

            $recent = $this->cachedGet('listens', ['count' => max(1, $limit)], $ttl);
            foreach ($recent['listens'] ?? [] as $l) {
                $s = self::toScrobble($l);
                if ($s !== null) {
                    $tracks[] = ScrobbleSources::lastfmShaped($s[0], $s[1], (string) ($l['track_metadata']['release_name'] ?? ''), $s[2]);
                }
            }
        } catch (Throwable $e) {
            // Live view degrades to whatever we already have — never breaks the card.
        }

        return $tracks;
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
