<?php

require_once __DIR__ . '/ScrobbleSources.php';

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
 *
 * Optional extra sources (ListenBrainz, Libre.fm — see
 * lib/ScrobbleSources.php) are imported into the same scrobble list by
 * syncImports() / backfillImports(), each with its own independent
 * backfill/sync cursors under state['imports']. Imported entries carry their
 * service name as a 4th element ([artist, track, ts, 'listenbrainz']; Last.fm
 * entries stay 3 elements, exactly as before), which is what lets every
 * merge de-duplicate a play recorded by two services without ever
 * collapsing two entries from the same service. With sources enabled, a
 * period only counts as "covered" once every enabled source has backfilled
 * that far back too — otherwise totals would silently mix complete and
 * partial histories.
 */
class LibrarySync
{
    private LastFm $lastfm;
    private string $file;
    private ?array $stateCache = null;
    private ?array $trackRankCache = null;
    private ?array $artistRankCache = null;
    /** @var ScrobbleSource[] */
    private array $sources;
    private int $dedupeSeconds;

    /**
     * @param ScrobbleSource[] $sources Extra services to import from — usually ScrobbleSources::fromConfig($config).
     */
    public function __construct(LastFm $lastfm, string $user, array $sources = [], int $dedupeSeconds = 180)
    {
        $this->lastfm = $lastfm;
        $this->sources = $sources;
        // Keyed by the Last.fm username as before (so existing snapshots are
        // kept); without a Last.fm account, by the import sources instead.
        $key = ($lastfm->hasUser() && $user !== '') ? $user : implode('|', array_map(fn($s) => $s->id(), $sources));
        $this->file = __DIR__ . '/../cache/library_' . md5($key) . '.json.gz';
        $this->dedupeSeconds = max(0, $dedupeSeconds);
    }

    /**
     * Builds a LibrarySync with whichever import sources config.php enables
     * — the one-liner every entry point (index.php, api.php, cron.php,
     * widgets.php, mcp.php) uses, so they all agree on what "covered" means.
     */
    public static function fromConfig(LastFm $lastfm, array $config): self
    {
        $sources = ScrobbleSources::fromConfig($config);
        $library = new self($lastfm, trim((string) ($config['username'] ?? '')), $sources, ScrobbleSources::dedupeSeconds($config));

        // No Last.fm account: the first configured service (ListenBrainz,
        // else Libre.fm) answers every Last.fm user.* call in its place, so
        // widgets, panels, Lifetime Stats and the MCP tools all run on it.
        if (!$lastfm->hasUser() && !empty($sources)) {
            $lastfm->setUserDelegate(new SourceUserApi($sources[0], $library));
        }
        $lastfm->setLovedSources($sources);

        // Last.fm account plus imports: Lifetime Stats include imported plays.
        // (With no Last.fm account, SourceUserApi::info() already does this.)
        if ($lastfm->hasUser() && !empty($sources)) {
            $lastfm->setLocalCountsProvider(fn() => $library->localCounts(true));
        }

        return $library;
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
            'scrobbles'         => [],   // each: [artist, track name, unix timestamp(, import service)]
            'imports'           => [],   // source id => {backfill_complete, backfill_before, synced_through}
        ];
    }

    /** Service name stored on imported entries: "listenbrainz:rob" -> "listenbrainz". */
    private static function serviceOf(string $sourceId): string
    {
        return explode(':', $sourceId, 2)[0];
    }

    /**
     * Appends $incoming scrobbles from $service to the state, skipping any
     * that another service already recorded — same artist + track
     * (case-insensitive) within $this->dedupeSeconds, matched one-to-one,
     * nearest timestamp first. With no import sources configured this is a
     * plain append, byte-for-byte the pre-import behaviour.
     *
     * @param array<int, array{0:string, 1:string, 2:int}> $incoming
     * @return int how many were actually added
     */
    private function mergeScrobbles(array &$state, array $incoming, string $service): int
    {
        if (empty($incoming)) {
            return 0;
        }

        $tag = fn(array $s) => $service === ScrobbleSources::LASTFM_ID ? [$s[0], $s[1], $s[2]] : [$s[0], $s[1], $s[2], $service];

        if (empty($this->sources)) {
            foreach ($incoming as $s) {
                $state['scrobbles'][] = $tag($s);
            }

            return count($incoming);
        }

        $window = $this->dedupeSeconds;
        $timestamps = array_column($incoming, 2);
        $lo = min($timestamps) - $window;
        $hi = max($timestamps) + $window;

        // Only other services' entries near this batch's time range can match.
        // This service's own entries are indexed too, by exact timestamp, so
        // re-walking history it already imported (see backfillImports())
        // doesn't add them twice.
        $index = [];
        $own = [];
        foreach ($state['scrobbles'] as $s) {
            if ($s[2] < $lo || $s[2] > $hi) {
                continue;
            }
            if (($s[3] ?? ScrobbleSources::LASTFM_ID) === $service) {
                $own[ScrobbleSources::matchKey($s[0], $s[1]) . "\x02" . $s[2]] = true;
                continue;
            }
            $index[ScrobbleSources::matchKey($s[0], $s[1])][] = $s[2];
        }

        $added = 0;
        foreach ($incoming as $s) {
            $key = ScrobbleSources::matchKey($s[0], $s[1]);
            if (isset($own[$key . "\x02" . $s[2]])) {
                continue; // already imported from this service
            }
            $best = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($index[$key] ?? [] as $i => $ts) {
                $diff = abs($ts - $s[2]);
                if ($diff <= $window && $diff < $bestDiff) {
                    $best = $i;
                    $bestDiff = $diff;
                }
            }

            if ($best !== null) {
                unset($index[$key][$best]); // consumed: one play absorbs one duplicate
                // Another service already holds this play. Counted per service
                // for localCounts() and backfillImports()'s completeness check.
                $state['dropped'][$service] = (int) ($state['dropped'][$service] ?? 0) + 1;
                continue;
            }

            $state['scrobbles'][] = $tag($s);
            $added++;
        }

        return $added;
    }

    /** Lazily-initialized cursor state for one import source, starting from "now". */
    private function importEntry(array $state, ScrobbleSource $source): array
    {
        $now = time();

        return ($state['imports'][$source->id()] ?? []) + [
            'label'             => $source->label(),
            'backfill_complete' => false,
            'backfill_before'   => $now,
            'synced_through'    => $now,
        ];
    }

    /** True once Last.fm and every enabled import source have backfilled to the very start. */
    private function fullyBackfilled(array $state): bool
    {
        // Without a Last.fm account there's no Last.fm backfill to wait for.
        if ($this->lastfm->hasUser() && !$state['backfill_complete']) {
            return false;
        }
        if (!$this->lastfm->hasUser() && empty($this->sources)) {
            return false; // nothing configured at all
        }

        foreach ($this->sources as $source) {
            if (empty($state['imports'][$source->id()]['backfill_complete'])) {
                return false;
            }
        }

        return true;
    }

    /** The most recent "backfilled back to" point across Last.fm and every enabled source. */
    private function effectiveBackfillBefore(array $state): int
    {
        if (!$this->lastfm->hasUser() && empty($this->sources)) {
            return PHP_INT_MAX; // nothing configured at all — covers nothing
        }

        // Without a Last.fm account only the import sources limit coverage.
        $before = $this->lastfm->hasUser() ? (int) $state['backfill_before'] : 0;

        foreach ($this->sources as $source) {
            $imp = $state['imports'][$source->id()] ?? null;
            if ($imp === null) {
                return PHP_INT_MAX; // enabled but never run yet — covers nothing
            }
            if (empty($imp['backfill_complete'])) {
                $before = max($before, (int) $imp['backfill_before']);
            }
        }

        return $before;
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
        if (!$this->lastfm->hasUser()) {
            return ['pages' => 0, 'scrobbles' => 0, 'complete' => true]; // no Last.fm account configured
        }

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
            $batch = [];
            foreach ($tracks as $t) {
                $uts = $t['date']['uts'] ?? null;
                $name = $t['name'] ?? '';
                $artist = $t['artist']['#text'] ?? ($t['artist']['name'] ?? '');
                if ($uts === null || $name === '' || $artist === '') {
                    continue; // the in-progress "now playing" entry has no date
                }

                $uts = (int) $uts;
                $batch[] = [$artist, $name, $uts];
                $oldestSeen = min($oldestSeen, $uts);
            }
            $added += $this->mergeScrobbles($state, $batch, ScrobbleSources::LASTFM_ID);

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
        if (!$this->lastfm->hasUser()) {
            return ['scrobbles' => 0]; // no Last.fm account configured
        }

        $state = $this->load();
        $since = $state['synced_through'];
        $added = 0;
        $fetched = 0;

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

            $batch = [];
            foreach ($tracks as $t) {
                $uts = $t['date']['uts'] ?? null;
                $name = $t['name'] ?? '';
                $artist = $t['artist']['#text'] ?? ($t['artist']['name'] ?? '');
                if ($uts === null || $name === '' || $artist === '') {
                    continue;
                }

                $uts = (int) $uts;
                $batch[] = [$artist, $name, $uts];
                $state['synced_through'] = max($state['synced_through'], $uts);
                $fetched++;
            }
            $added += $this->mergeScrobbles($state, $batch, ScrobbleSources::LASTFM_ID);

            $totalPages = (int) ($data['recenttracks']['@attr']['totalPages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
        }

        if ($fetched > 0) {
            $this->save($state); // synced_through moved even if every new one was a duplicate
        }

        return ['scrobbles' => $added];
    }

    /**
     * syncRecent()'s equivalent for every enabled import source: pulls each
     * one's scrobbles since its own last sync point and merges them in. One
     * failing source doesn't stop the others; its error is reported in its
     * result entry instead and it simply retries next run.
     *
     * @return array<string, array{label: string, scrobbles: int, error?: string}> keyed by source id
     */
    public function syncImports(): array
    {
        if (empty($this->sources)) {
            return [];
        }

        $state = $this->load();
        $results = [];

        foreach ($this->sources as $source) {
            $imp = $this->importEntry($state, $source);
            $result = ['label' => $source->label(), 'scrobbles' => 0];

            try {
                $incoming = $source->fetchSince((int) $imp['synced_through']);
                if ($incoming) {
                    $imp['synced_through'] = max((int) $imp['synced_through'], max(array_column($incoming, 2)));
                    $result['scrobbles'] = $this->mergeScrobbles($state, $incoming, self::serviceOf($source->id()));
                }
            } catch (Throwable $e) {
                $result['error'] = $e->getMessage();
            }

            $state['imports'][$source->id()] = $imp;
            $results[$source->id()] = $result;
        }

        $this->save($state);

        return $results;
    }

    /**
     * backfillBatch()'s equivalent for every enabled import source: up to
     * $maxPages more pages of each one's older history per run, walking
     * backward from when that source was first enabled. Per-source errors
     * are reported, not thrown, same as syncImports().
     *
     * @return array<string, array{label: string, pages: int, scrobbles: int, complete: bool, error?: string}> keyed by source id
     */
    public function backfillImports(int $maxPages): array
    {
        if (empty($this->sources)) {
            return [];
        }

        $state = $this->load();
        $results = [];

        foreach ($this->sources as $source) {
            $imp = $this->importEntry($state, $source);
            $result = ['label' => $source->label(), 'pages' => 0, 'scrobbles' => 0, 'complete' => (bool) $imp['backfill_complete']];

            if ($imp['backfill_complete'] && $this->missingHistory($state, $source, $imp)) {
                // History was added on the service after we finished (e.g. a
                // Spotify or Last.fm import into ListenBrainz). Those listens
                // have old timestamps, so the forward sync never sees them:
                // walk the whole history again. Already-imported listens are
                // skipped by mergeScrobbles(), and drops are recounted.
                $imp['backfill_complete'] = false;
                $imp['backfill_before'] = time();
                $state['dropped'][self::serviceOf($source->id())] = 0;
                $result['complete'] = false;
                $result['rescan'] = true;
            }

            if (!$imp['backfill_complete']) {
                try {
                    $batch = $source->fetchBefore((int) $imp['backfill_before'], max(1, $maxPages));
                    $result['pages'] = $batch['pages'];

                    if ($batch['scrobbles']) {
                        $imp['backfill_before'] = max(0, min(array_column($batch['scrobbles'], 2)) - 1);
                        $result['scrobbles'] = $this->mergeScrobbles($state, $batch['scrobbles'], self::serviceOf($source->id()));
                    }
                    if ($batch['end']) {
                        $imp['backfill_complete'] = true;
                        $result['complete'] = true;
                    }
                } catch (Throwable $e) {
                    $result['error'] = $e->getMessage();
                }
            }

            $state['imports'][$source->id()] = $imp;
            $results[$source->id()] = $result;
        }

        $this->save($state);

        return $results;
    }

    /**
     * True when $source reports noticeably more listens than this library
     * has seen from it (kept, plus dropped as another service's duplicate).
     * Checked at most every 6 hours; the margin allows for listens made
     * since the last sync.
     */
    private function missingHistory(array $state, ScrobbleSource $source, array &$imp): bool
    {
        if (time() - (int) ($imp['checked_at'] ?? 0) < 21600) {
            return false;
        }
        $imp['checked_at'] = time();

        try {
            $live = (int) ($source->nativeUserCall('user.getinfo', [])['user']['playcount'] ?? 0);
        } catch (Throwable $e) {
            return false;
        }
        if ($live <= 0) {
            return false;
        }

        $service = self::serviceOf($source->id());
        $seen = (int) ($state['dropped'][$service] ?? 0);
        foreach ($state['scrobbles'] as $s) {
            if (($s[3] ?? ScrobbleSources::LASTFM_ID) === $service) {
                $seen++;
            }
        }

        return $live > $seen + max(25, (int) ($live * 0.01));
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
        return $sinceUnix <= 0 ? $this->fullyBackfilled($state) : $sinceUnix >= $this->effectiveBackfillBefore($state);
    }

    /**
     * @return array<int, array{name:string, artist:array{name:string}, playcount:int, image:array}>|null
     */
    public function topTracks(int $sinceUnix, int $limit, ?int $untilUnix = null): ?array
    {
        $state = $this->load();
        if (!$this->covers($state, $sinceUnix)) {
            return null;
        }

        $counts = [];
        foreach ($state['scrobbles'] as [$artist, $name, $ts]) {
            if ($ts < $sinceUnix || ($untilUnix !== null && $ts > $untilUnix)) {
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
    public function topArtistPlaycounts(int $sinceUnix, ?int $untilUnix = null): ?array
    {
        $state = $this->load();
        if (!$this->covers($state, $sinceUnix)) {
            return null;
        }

        $counts = [];
        foreach ($state['scrobbles'] as [$artist, $name, $ts]) {
            if ($ts < $sinceUnix || ($untilUnix !== null && $ts > $untilUnix)) {
                continue;
            }
            $counts[$artist] = ($counts[$artist] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Individual scrobbles (artist, track, exact timestamp) within
     * [$sinceUnix, $untilUnix], newest first — the raw data behind every
     * other query method here, for callers (the MCP server) that want
     * arbitrary date/time-level detail rather than a period aggregate. Null
     * if the snapshot doesn't reach back to $sinceUnix yet.
     *
     * @return array<int, array{artist:string, track:string, timestamp:int}>|null
     */
    public function rawScrobbles(int $sinceUnix, ?int $untilUnix, int $limit): ?array
    {
        $state = $this->load();
        if (!$this->covers($state, $sinceUnix)) {
            return null;
        }

        $matches = [];
        foreach ($state['scrobbles'] as [$artist, $name, $ts]) {
            if ($ts < $sinceUnix || ($untilUnix !== null && $ts > $untilUnix)) {
                continue;
            }
            $matches[] = ['artist' => $artist, 'track' => $name, 'timestamp' => $ts];
        }

        usort($matches, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($matches, 0, max(1, $limit));
    }

    /**
     * Snapshot status — how far back local history reaches and whether
     * it's fully caught up — so a caller can honestly report why a query
     * came back empty or unavailable rather than silently doing so.
     */
    public function coverage(): array
    {
        $state = $this->load();

        $imports = [];
        foreach ($this->sources as $source) {
            $imp = $state['imports'][$source->id()] ?? null;
            $imports[] = [
                'source'            => $source->label(),
                'started'           => $imp !== null,
                'backfill_complete' => (bool) ($imp['backfill_complete'] ?? false),
                'backfill_before'   => $imp['backfill_before'] ?? null,
                'synced_through'    => $imp['synced_through'] ?? null,
            ];
        }

        $before = $this->effectiveBackfillBefore($state);

        return [
            'backfill_complete' => $this->fullyBackfilled($state),
            'backfill_before'   => $before === PHP_INT_MAX ? null : $before,
            'synced_through'    => $this->lastfm->hasUser() ? $state['synced_through'] : null,
            'scrobble_count'    => count($state['scrobbles']),
            'lastfm'            => $this->lastfm->hasUser(),
            'imports'           => $imports,
        ];
    }

    /**
     * Totals from local history, for Lifetime Stats:
     *  - scrobbles / artists / tracks / first: everything in the library
     *    (merged across services, de-duplicated) and the earliest listen.
     *  - imports_only: imported plays with no Last.fm copy, i.e. what to add
     *    to Last.fm's own live total. Only imports inside the period Last.fm
     *    has already backfilled count, since older ones can't have been
     *    checked against Last.fm yet. Imports that a later Last.fm scrobble
     *    matched (the Last.fm copy was dropped, see mergeScrobbles()) are
     *    taken back off.
     *  - complete: whether every service has backfilled to the start.
     * Null with nothing imported yet, or (unless $allowPartial) until
     * complete. Cached for 15 minutes, as api.php asks on every poll.
     *
     * @return array{scrobbles:int, artists:int, tracks:int, first:int, imports_only:int, complete:bool}|null
     */
    public function localCounts(bool $allowPartial = false): ?array
    {
        $state = $this->load();
        if (empty($state['scrobbles'])) {
            return null;
        }

        $counts = null;
        $cacheFile = $this->file . '.counts.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['scrobbles'], $cached['first'], $cached['imports_only'], $cached['complete'])) {
                $counts = $cached;
            }
        }

        if ($counts === null) {
            $lastfmCoveredFrom = (!$this->lastfm->hasUser() || $state['backfill_complete']) ? 0 : (int) $state['backfill_before'];
            $artists = [];
            $tracks = [];
            $first = PHP_INT_MAX;
            $imports = 0;
            foreach ($state['scrobbles'] as $s) {
                $artistKey = strtolower($s[0]);
                $artists[$artistKey] = true;
                $tracks[$artistKey . "\x01" . strtolower($s[1])] = true;
                $first = min($first, $s[2]);
                if (isset($s[3]) && $s[2] >= $lastfmCoveredFrom) {
                    $imports++; // imported entries carry their service as a 4th element
                }
            }

            $counts = [
                'scrobbles'    => count($state['scrobbles']),
                'artists'      => count($artists),
                'tracks'       => count($tracks),
                'first'        => $first,
                'imports_only' => max(0, $imports - (int) ($state['dropped'][ScrobbleSources::LASTFM_ID] ?? 0)),
                'complete'     => $this->fullyBackfilled($state),
            ];
            @file_put_contents($cacheFile, json_encode($counts));
        }

        return ($allowPartial || $counts['complete']) ? $counts : null;
    }

    /**
     * Every scrobble, newest first, as [artist, track, timestamp] — null
     * until fully backfilled, unless $allowPartial (then whatever has been
     * imported so far, null only if nothing has). Memoized, since a paged
     * walk (Listening Clock) asks for one page after another.
     *
     * @return array<int, array{0:string, 1:string, 2:int}>|null
     */
    public function allScrobblesDesc(bool $allowPartial = false): ?array
    {
        static $memo = [];

        $state = $this->load();
        if ($allowPartial ? empty($state['scrobbles']) : !$this->fullyBackfilled($state)) {
            return null;
        }

        $key = $this->file . ':' . count($state['scrobbles']);
        if (!isset($memo[$key])) {
            $all = array_map(fn($s) => [$s[0], $s[1], $s[2]], $state['scrobbles']);
            usort($all, fn($a, $b) => $b[2] <=> $a[2]);
            $memo = [$key => $all];
        }

        return $memo[$key];
    }

    /** How many times you've played this track, from local history (case-insensitive). */
    public function playcountFor(string $artist, string $track): int
    {
        $artistLower = strtolower($artist);
        $trackLower = strtolower($track);
        $count = 0;

        foreach ($this->load()['scrobbles'] as $s) {
            if (strtolower($s[1]) === $trackLower && strtolower($s[0]) === $artistLower) {
                $count++;
            }
        }

        return $count;
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

            $cached = $this->lastfm->call('artist.gettoptags', ['artist' => $name], 604800, true);
            $refetch = $cached !== null && !$this->lastfm->hasKey() && $this->lastfm->userDelegate() !== null
                && SourceUserApi::needsRefetch('artist.gettoptags', $cached);
            if ($cached !== null && !$refetch) {
                continue; // already cached — doesn't count against this run's batch
            }

            // With no Last.fm API key these come from MusicBrainz (see
            // lib/SourceUserApi.php), paced at ~1/s. This paced batch is the
            // only place they're allowed to run live; Genre Breakdown and
            // Mood Weather only ever read tags fetched here.
            SourceUserApi::allowSlowLookups(true);
            try {
                $this->lastfm->call('artist.gettoptags', ['artist' => $name], 604800);
            } finally {
                SourceUserApi::allowSlowLookups(false);
            }
            $tagged++;
        }

        return ['tagged' => $tagged, 'total_artists' => count($counts)];
    }

    /**
     * Every all-time-local-playcount rank in one pass: memoized per
     * instance, and additionally cached to a file for 15 minutes, since
     * api.php calls into this on every poll (every ~10s by default) — a
     * large library's full scrobble list is cheap to scan/sort once, not
     * worth redoing on every single poll. An "all-time rank" claim only
     * means something once the full history is backfilled.
     *
     * @return array{ranks: array<string,int>, total: int}|null
     */
    private function computeAllTimeRanking(bool $byArtist): ?array
    {
        $cache = $byArtist ? $this->artistRankCache : $this->trackRankCache;
        if ($cache !== null) {
            return $cache;
        }

        $state = $this->load();
        if (!$this->fullyBackfilled($state)) {
            return null;
        }

        $cacheFile = $this->file . ($byArtist ? '.artist_ranks.json' : '.track_ranks.json');
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['ranks'], $cached['total'])) {
                if ($byArtist) {
                    $this->artistRankCache = $cached;
                } else {
                    $this->trackRankCache = $cached;
                }

                return $cached;
            }
        }

        $counts = [];
        foreach ($state['scrobbles'] as [$artist, $name, ]) {
            $key = $byArtist ? strtolower($artist) : (strtolower($artist) . "\x01" . strtolower($name));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        $ranks = [];
        $rank = 0;
        foreach ($counts as $key => $count) {
            $ranks[$key] = ++$rank;
        }

        $result = ['ranks' => $ranks, 'total' => count($ranks)];
        @file_put_contents($cacheFile, json_encode($result));

        if ($byArtist) {
            $this->artistRankCache = $result;
        } else {
            $this->trackRankCache = $result;
        }

        return $result;
    }

    private static function ordinal(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n . 'th';
        }

        return $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    }

    private static function humanDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        if ($days >= 365) {
            $years = intdiv($days, 365);
            return $years . ' year' . ($years === 1 ? '' : 's');
        }
        if ($days >= 30) {
            $months = intdiv($days, 30);
            return $months . ' month' . ($months === 1 ? '' : 's');
        }

        return $days . ' day' . ($days === 1 ? '' : 's');
    }

    /**
     * All-time rank, first-scrobbled date, and a human recency/streak line
     * for this exact track — everything the hover tooltip's "insights"
     * section needs, in one call. Every piece is independently omitted
     * (null, or left out of the recency line) when local coverage can't
     * support it honestly yet, rather than guessed at from a partial
     * history: all-time rank and "first scrobbled" need the full history
     * backfilled; "Nth play this week" only needs coverage back to the
     * start of this week, a much lower bar usually met almost immediately.
     *
     * @return array{
     *     track_rank: array{rank:int, total:int}|null,
     *     artist_rank: array{rank:int, total:int}|null,
     *     first_scrobbled: int|null,
     *     recency: string|null
     * }
     */
    public function trackInsights(string $artist, string $name, DateTimeZone $tz): array
    {
        $insights = ['track_rank' => null, 'artist_rank' => null, 'first_scrobbled' => null, 'recency' => null];

        $trackKey = strtolower($artist) . "\x01" . strtolower($name);
        $trackRanking = $this->computeAllTimeRanking(false);
        if ($trackRanking !== null && isset($trackRanking['ranks'][$trackKey])) {
            $insights['track_rank'] = ['rank' => $trackRanking['ranks'][$trackKey], 'total' => $trackRanking['total']];
        }

        $artistRanking = $this->computeAllTimeRanking(true);
        if ($artistRanking !== null && isset($artistRanking['ranks'][strtolower($artist)])) {
            $insights['artist_rank'] = ['rank' => $artistRanking['ranks'][strtolower($artist)], 'total' => $artistRanking['total']];
        }

        $state = $this->load();
        $artistLower = strtolower($artist);
        $nameLower = strtolower($name);
        $allTimestamps = [];
        foreach ($state['scrobbles'] as [$a, $n, $ts]) {
            if (strtolower($a) === $artistLower && strtolower($n) === $nameLower) {
                $allTimestamps[] = $ts;
            }
        }
        sort($allTimestamps);

        if ($this->fullyBackfilled($state) && !empty($allTimestamps)) {
            $insights['first_scrobbled'] = $allTimestamps[0];

            $total = count($allTimestamps);
            if ($total === 1) {
                $insights['recency'] = "first time you've scrobbled this";
            } else {
                $gapSeconds = $allTimestamps[$total - 1] - $allTimestamps[$total - 2];
                if ($gapSeconds >= 30 * 86400) {
                    $insights['recency'] = 'first play in ' . self::humanDuration($gapSeconds);
                }
            }
        }

        if ($insights['recency'] === null) {
            $weekStart = self::periodStart('this_week', $tz);
            $thisWeekCount = 0;
            foreach ($allTimestamps as $ts) {
                if ($ts >= $weekStart) {
                    $thisWeekCount++;
                }
            }
            if ($this->covers($state, $weekStart) && $thisWeekCount >= 2) {
                $insights['recency'] = self::ordinal($thisWeekCount) . ' play this week';
            }
        }

        return $insights;
    }
}
