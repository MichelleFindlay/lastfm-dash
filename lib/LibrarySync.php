<?php

/**
 * Maintains a local, gzip-compressed snapshot of the user's full scrobble
 * history (artist + track + timestamp per scrobble) under cache/, built up
 * in bounded batches by cron.php rather than fetched live on every request.
 *
 * Once a UI period's start date falls inside what's been backfilled,
 * Favourite Tracks / Trending / Genre Breakdown can be computed straight
 * from this file: with exact calendar boundaries (real Jan 1, real
 * 1st-of-month, real Monday) instead of Last.fm's approximate
 * rolling-window `period` parameter, and with zero live API calls. Every
 * query method returns null when the snapshot doesn't cover the requested
 * period yet (a fresh install, or a still-in-progress backfill) — callers
 * fall back to LastFm's live methods in that case.
 *
 * Backfill walks backward from the moment the snapshot was first created,
 * anchored by scrobble timestamp (Last.fm's `to` param) rather than page
 * number, since new scrobbles arriving between cron runs would otherwise
 * shift page boundaries and cause pages to be skipped or re-read. New
 * scrobbles since that same starting moment are picked up separately (and
 * far more cheaply) by syncRecent(), anchored by `from` the same way
 * LastFm's own "today" methods already work.
 */
class LibrarySync
{
    private LastFm $lastfm;
    private string $file;
    private ?array $stateCache = null;

    public function __construct(LastFm $lastfm, string $user)
    {
        $this->lastfm = $lastfm;
        $this->file = __DIR__ . '/../cache/library_' . md5($user) . '.json.gz';
    }

    private function load(): array
    {
        if ($this->stateCache !== null) {
            return $this->stateCache;
        }

        $data = null;
        if (is_file($this->file)) {
            $raw = @file_get_contents($this->file);
            $json = $raw !== false ? @gzdecode($raw) : false;
            $data = $json !== false ? json_decode($json, true) : null;
        }

        $this->stateCache = is_array($data) ? ($data + $this->emptyState()) : $this->emptyState();

        return $this->stateCache;
    }

    private function save(array $state): void
    {
        $this->stateCache = $state;

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->file, gzencode(json_encode($state), 6));
    }

    private function emptyState(): array
    {
        $now = time();

        return [
            'backfill_complete' => false,
            'backfill_before'   => $now, // walks backward from snapshot creation time
            'synced_through'    => $now, // walks forward from the same point
            'scrobbles'         => [],   // each: [artist, track name, unix timestamp]
        ];
    }

    /**
     * Fetches up to $maxPages older pages of real (dated) scrobbles. Meant
     * to be called from cron.php every run, paced across many runs for a
     * large library — each batch is a handful of cheap calls, never one
     * enormous one.
     *
     * @return array{pages: int, scrobbles: int, complete: bool}
     */
    public function backfillBatch(int $maxPages): array
    {
        $state = $this->load();

        if ($state['backfill_complete']) {
            return ['pages' => 0, 'scrobbles' => 0, 'complete' => true];
        }

        // Fixed for the whole batch: Last.fm's `page` param walks pages of
        // one `to`-anchored query, so changing `to` between pages of the
        // same batch would silently skip or re-order results. Only once
        // this batch is done do we know the new (smaller) anchor to resume
        // from next time.
        $anchor = $state['backfill_before'];
        $oldestSeen = $anchor;
        $pages = 0;
        $added = 0;
        $reachedEnd = false;

        for ($page = 1; $page <= $maxPages; $page++) {
            $data = $this->lastfm->call('user.getrecenttracks', [
                'limit' => 200,
                'page'  => $page,
                'to'    => $anchor,
            ], 2592000); // a month — a given (page, to) batch of real history never changes

            $tracks = $data['recenttracks']['track'] ?? [];
            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }

            if (empty($tracks)) {
                $reachedEnd = true;
                break;
            }

            $pages++;
            foreach ($tracks as $t) {
                $uts = $t['date']['uts'] ?? null;
                $name = $t['name'] ?? '';
                $artist = $t['artist']['#text'] ?? ($t['artist']['name'] ?? '');
                if ($uts === null || $name === '' || $artist === '') {
                    continue; // the in-progress "now playing" entry has no date
                }

                $uts = (int) $uts;
                $state['scrobbles'][] = [$artist, $name, $uts];
                $added++;
                $oldestSeen = min($oldestSeen, $uts);
            }

            $totalPages = (int) ($data['recenttracks']['@attr']['totalPages'] ?? 1);
            if ($page >= $totalPages) {
                $reachedEnd = true;
                break;
            }
        }

        $state['backfill_before'] = max(0, $oldestSeen - 1);

        if ($reachedEnd) {
            $state['backfill_complete'] = true;
        }

        $this->save($state);

        return ['pages' => $pages, 'scrobbles' => $added, 'complete' => $state['backfill_complete']];
    }

    /**
     * Pulls any scrobbles newer than the last sync point. Cheap and safe to
     * call every cron run regardless of backfill progress — Last.fm's
     * `from` parameter is timestamp-anchored, so unlike backfillBatch()'s
     * page walk it can't be thrown off by new scrobbles arriving mid-sync.
     * Capped at 10 pages (2,000 scrobbles) as a safety limit, same as
     * LastFm's own "today" pagination — a normal 15-minute gap between cron
     * runs is nowhere near that.
     *
     * @return array{scrobbles: int}
     */
    public function syncRecent(): array
    {
        $state = $this->load();
        $since = $state['synced_through'];
        $added = 0;

        for ($page = 1; $page <= 10; $page++) {
            $data = $this->lastfm->call('user.getrecenttracks', [
                'limit' => 200,
                'page'  => $page,
                'from'  => $since + 1,
            ], 60);

            $tracks = $data['recenttracks']['track'] ?? [];
            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }

            if (empty($tracks)) {
                break;
            }

            foreach ($tracks as $t) {
                $uts = $t['date']['uts'] ?? null;
                $name = $t['name'] ?? '';
                $artist = $t['artist']['#text'] ?? ($t['artist']['name'] ?? '');
                if ($uts === null || $name === '' || $artist === '') {
                    continue;
                }

                $uts = (int) $uts;
                $state['scrobbles'][] = [$artist, $name, $uts];
                $state['synced_through'] = max($state['synced_through'], $uts);
                $added++;
            }

            $totalPages = (int) ($data['recenttracks']['@attr']['totalPages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
        }

        if ($added > 0) {
            $this->save($state);
        }

        return ['scrobbles' => $added];
    }

    /**
     * Exact calendar start timestamp for a UI period key, in $tz. Replaces
     * Last.fm's approximate rolling-window `period` parameter now that real
     * per-scrobble dates are available locally.
     */
    public static function periodStart(string $uiPeriod, DateTimeZone $tz): int
    {
        $today = new DateTime('today', $tz); // 00:00:00 today

        switch ($uiPeriod) {
            case 'today':
                return $today->getTimestamp();
            case 'this_week':
                $isoDow = (int) $today->format('N'); // 1 (Mon) .. 7 (Sun)
                return (clone $today)->modify('-' . ($isoDow - 1) . ' days')->getTimestamp();
            case 'this_month':
                return (clone $today)->modify('first day of this month')->getTimestamp();
            case 'this_year':
                return (new DateTime($today->format('Y') . '-01-01', $tz))->getTimestamp();
            default:
                return 0; // all_time
        }
    }

    private function covers(array $state, int $sinceUnix): bool
    {
        return $sinceUnix <= 0 ? $state['backfill_complete'] : $sinceUnix >= $state['backfill_before'];
    }

    /**
     * @return array<int, array{name:string, artist:array{name:string}, playcount:int, image:array}>|null
     */
    private function topTracks(int $sinceUnix, int $limit): ?array
    {
        $state = $this->load();
        if (!$this->covers($state, $sinceUnix)) {
            return null;
        }

        $counts = [];
        foreach ($state['scrobbles'] as [$artist, $name, $ts]) {
            if ($ts < $sinceUnix) {
                continue;
            }

            $key = $artist . "\x01" . $name;
            if (!isset($counts[$key])) {
                $counts[$key] = ['name' => $name, 'artist' => ['name' => $artist], 'playcount' => 0, 'image' => []];
            }
            $counts[$key]['playcount']++;
        }

        usort($counts, fn($a, $b) => $b['playcount'] <=> $a['playcount']);

        return array_slice(array_values($counts), 0, $limit);
    }

    /**
     * @return array<string, int>|null artist name => playcount, descending
     */
    private function topArtistPlaycounts(int $sinceUnix): ?array
    {
        $state = $this->load();
        if (!$this->covers($state, $sinceUnix)) {
            return null;
        }

        $counts = [];
        foreach ($state['scrobbles'] as [$artist, $name, $ts]) {
            if ($ts < $sinceUnix) {
                continue;
            }
            $counts[$artist] = ($counts[$artist] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Top tracks for a UI period key, or null if the snapshot doesn't cover
     * it yet. Same return shape as LastFm::getTracksForUiPeriod(), so
     * callers can use either interchangeably:
     * $library->tracksForPeriod(...) ?? $lastfm->getTracksForUiPeriod(...)
     */
    public function tracksForPeriod(string $uiPeriod, int $limit, DateTimeZone $tz): ?array
    {
        return $this->topTracks(self::periodStart($uiPeriod, $tz), $limit);
    }

    /**
     * Genre breakdown for a UI period key, or null if the snapshot doesn't
     * cover it yet. Scores every distinct artist scrobbled in that period
     * (not a capped sample — the whole point of having the full history
     * locally), so the result includes every genre found with no "Other"
     * catch-all bucket. Only artists whose tags are already cached
     * contribute (cache-only — never triggers a live lookup from a page
     * request); backfillArtistTags() is what actually fills that cache in,
     * paced over many cron runs, so coverage — and how complete this
     * breakdown is — grows over time rather than needing a lookup burst
     * covering potentially thousands of artists in one request.
     */
    public function genresForUiPeriod(string $uiPeriod, DateTimeZone $tz, bool $onlySpotifyGenres = false): ?array
    {
        $artistCounts = $this->topArtistPlaycounts(self::periodStart($uiPeriod, $tz));
        if ($artistCounts === null) {
            return null;
        }

        if (empty($artistCounts)) {
            return [];
        }

        $scores = $this->lastfm->scoreGenreTags($artistCounts, true, $onlySpotifyGenres);

        return LastFm::genresFromScores($scores, 0, false);
    }

    /**
     * Fetches Last.fm tags for up to $maxArtists distinct artists from the
     * local scrobble history that don't have cached tags yet, heaviest
     * playcount first (so the artists that matter most to the breakdown
     * gain coverage soonest). Meant to be called from cron.php every run,
     * paced across many runs the same way backfillBatch() paces the
     * scrobble history itself — a library with thousands of distinct
     * artists would otherwise need a lookup burst covering all of them at
     * once just to compute one Genre Breakdown.
     *
     * @return array{tagged: int, total_artists: int}
     */
    public function backfillArtistTags(int $maxArtists): array
    {
        $state = $this->load();

        $counts = [];
        foreach ($state['scrobbles'] as [$artist, , ]) {
            $counts[$artist] = ($counts[$artist] ?? 0) + 1;
        }
        arsort($counts);

        $tagged = 0;
        foreach (array_keys($counts) as $name) {
            if ($tagged >= $maxArtists) {
                break;
            }

            if ($this->lastfm->call('artist.gettoptags', ['artist' => $name], 604800, true) !== null) {
                continue; // already cached — doesn't count against this run's batch
            }

            $this->lastfm->call('artist.gettoptags', ['artist' => $name], 604800);
            $tagged++;
        }

        return ['tagged' => $tagged, 'total_artists' => count($counts)];
    }
}
