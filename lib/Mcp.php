<?php

require_once __DIR__ . '/WidgetRegistry.php';

/**
 * Defines and executes the tools exposed over mcp.php — see that file for
 * the transport/auth layer. Read-only: every tool just reads existing
 * Last.fm / local-library data, nothing here modifies anything.
 */
class Mcp
{
    private const WIDGET_TOOLS = [
        'listening_clock' => 'A 24-hour breakdown of when you actually listen, hour by hour.',
        'energy_curve'    => 'Scrobble activity across the days of the week (listening volume, not audio tempo — Last.fm has no tempo data).',
        'distance'        => 'Estimated total listening time, converted into flights, marathons, full days, and (for heavy listeners) trips to the Moon or Mars, each with how far into the current unit you are.',
        'festival'        => 'Your top artists billed as a festival poster (headliner / main stage / tent stage tiers by play rank).',
        'mood'            => "A monthly emotional forecast derived from your top artists' community tags.",
        'bpm'             => 'Average tempo across a sample of your top tracks, sourced from Deezer since Last.fm has no tempo data of its own.',
        'before_famous'   => 'Your top artists with the lowest current global Last.fm listener counts, verified against Spotify when configured.',
        'obscurity'       => 'Average/median global Last.fm listener count across your top artists — how mainstream your taste really is.',
    ];

    private const PERIODS = ['all_time', 'this_year', 'this_month', 'this_week', 'today'];

    private LastFm $lastfm;
    private Widgets $widgets;
    private LibrarySync $library;
    private array $config;
    private array $handlers;

    public function __construct(LastFm $lastfm, Widgets $widgets, LibrarySync $library, array $config)
    {
        $this->lastfm = $lastfm;
        $this->widgets = $widgets;
        $this->library = $library;
        $this->config = $config;
        $this->handlers = WidgetRegistry::handlers($lastfm, $widgets, $config, $library);
    }

    public function initialize(): array
    {
        $versionFile = __DIR__ . '/../VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';

        return [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'lastfm-dash', 'version' => $version],
        ];
    }

    public function listTools(): array
    {
        $tools = [
            [
                'name'        => 'get_now_playing',
                'description' => 'The currently playing (or most recently played) track, plus the track played immediately before it.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            ],
            [
                'name'        => 'list_scrobbles',
                'description' => 'Individual listening history entries (artist, track, exact date/time) from the locally cached scrobble history — use this for anything date/time-specific that the period-based tools below don\'t cover.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'since' => ['type' => 'string', 'description' => 'ISO 8601 date/datetime, e.g. "2024-01-01". Omit for the start of your history.'],
                        'until' => ['type' => 'string', 'description' => 'ISO 8601 date/datetime. Omit for now.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max entries to return (default 200, max 2000).'],
                    ],
                ],
            ],
            [
                'name'        => 'top_artists',
                'description' => 'Your top artists by play count for a period.',
                'inputSchema' => $this->periodSchema('artists'),
            ],
            [
                'name'        => 'top_tracks',
                'description' => 'Your top tracks by play count for a period.',
                'inputSchema' => $this->periodSchema('tracks'),
            ],
            [
                'name'        => 'genre_breakdown',
                'description' => "Genre breakdown (percentage per genre) derived from your top artists' community tags for a period.",
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'period'               => ['type' => 'string', 'enum' => self::PERIODS, 'description' => 'Defaults to all_time.'],
                        'only_spotify_genres'  => ['type' => 'boolean', 'description' => "Restrict to Spotify's own genre vocabulary, filtering out tag noise. Defaults to true when Spotify credentials are configured, false otherwise."],
                    ],
                ],
            ],
            [
                'name'        => 'lifetime_stats',
                'description' => 'Lifetime account totals: scrobble count, unique artist/album/track counts, average scrobbles per day, member-since date.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            ],
        ];

        foreach (self::WIDGET_TOOLS as $id => $description) {
            $tools[] = [
                'name'        => 'widget_' . $id,
                'description' => $description,
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            ];
        }

        return $tools;
    }

    private function periodSchema(string $kind): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => ['type' => 'string', 'enum' => self::PERIODS, 'description' => 'Defaults to all_time.'],
                'limit'  => ['type' => 'integer', 'description' => 'Max ' . $kind . ' to return (default 20, max 200).'],
            ],
        ];
    }

    /**
     * @return array{content: array<int, array{type:string, text:string}>, isError: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        try {
            $data = $this->dispatch($name, $arguments);

            return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT)]], 'isError' => false];
        } catch (Throwable $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
    }

    private function dispatch(string $name, array $args): array
    {
        if (strpos($name, 'widget_') === 0 && isset(self::WIDGET_TOOLS[substr($name, 7)])) {
            $id = substr($name, 7);

            return WidgetCache::remember($id, ['id' => $id], 900, $this->handlers[$id]);
        }

        switch ($name) {
            case 'get_now_playing':
                return $this->getNowPlaying();
            case 'list_scrobbles':
                return $this->listScrobbles($args);
            case 'top_artists':
                return $this->topArtists($args);
            case 'top_tracks':
                return $this->topTracks($args);
            case 'genre_breakdown':
                return $this->genreBreakdown($args);
            case 'lifetime_stats':
                return LastFm::formatLifetimeStats($this->lastfm->getInfo());
            default:
                throw new InvalidArgumentException('Unknown tool: ' . $name);
        }
    }

    private function getNowPlaying(): array
    {
        $recent = $this->lastfm->getRecentTracks(4);
        $tracks = $recent['recenttracks']['track'] ?? [];
        if (isset($tracks['name'])) {
            $tracks = [$tracks];
        }
        $track = $tracks[0] ?? null;

        if (!$track) {
            return ['available' => false];
        }

        $artist = $track['artist']['#text'] ?? ($track['artist']['name'] ?? '');
        $previousRaw = LastFm::findPreviousTrack($tracks, $artist, $track['name'] ?? '');

        return [
            'available'   => true,
            'now_playing' => ($track['@attr']['nowplaying'] ?? '') === 'true',
            'current'     => [
                'name'   => $track['name'] ?? '',
                'artist' => $artist,
                'album'  => $track['album']['#text'] ?? '',
                'date'   => $track['date']['#text'] ?? null,
            ],
            'previous' => $previousRaw ? [
                'name'   => $previousRaw['name'] ?? '',
                'artist' => $previousRaw['artist']['#text'] ?? ($previousRaw['artist']['name'] ?? ''),
                'album'  => $previousRaw['album']['#text'] ?? '',
                'date'   => $previousRaw['date']['#text'] ?? null,
            ] : null,
        ];
    }

    private function parseWhen(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $ts = strtotime($value);

        return $ts !== false ? $ts : null;
    }

    private function listScrobbles(array $args): array
    {
        $since = $this->parseWhen($args['since'] ?? null) ?? 0;
        $until = $this->parseWhen($args['until'] ?? null);
        $limit = max(1, min(2000, (int) ($args['limit'] ?? 200)));

        $scrobbles = $this->library->rawScrobbles($since, $until, $limit);

        if ($scrobbles === null) {
            return [
                'available' => false,
                'message'   => 'Local listening history does not reach back that far yet — it backfills gradually via cron.php.',
                'coverage'  => $this->library->coverage(),
            ];
        }

        return [
            'available' => true,
            'count'     => count($scrobbles),
            'scrobbles' => array_map(fn($s) => [
                'artist'    => $s['artist'],
                'track'     => $s['track'],
                'timestamp' => $s['timestamp'],
                'datetime'  => date('c', $s['timestamp']),
            ], $scrobbles),
        ];
    }

    private function periodAndTz(array $args): array
    {
        $period = LastFm::validUiPeriod($args['period'] ?? 'all_time', 'all_time');
        $tz = LastFm::resolveTimezone($this->config['timezone'] ?? '');

        return [$period, $tz];
    }

    private function topArtists(array $args): array
    {
        [$period, $tz] = $this->periodAndTz($args);
        $limit = max(1, min(200, (int) ($args['limit'] ?? 20)));

        $counts = $this->library->topArtistPlaycounts(LibrarySync::periodStart($period, $tz));
        if ($counts !== null) {
            $artists = [];
            foreach (array_slice($counts, 0, $limit, true) as $artistName => $playcount) {
                $artists[] = ['name' => $artistName, 'playcount' => $playcount];
            }

            return ['available' => true, 'period' => $period, 'source' => 'local', 'artists' => $artists];
        }

        // Live fallback — Last.fm only offers fixed rolling periods (no
        // arbitrary date-range top-artists endpoint), so this maps onto its
        // closest equivalent the same way the rest of the app does.
        $map = ['all_time' => 'overall', 'this_year' => '12month', 'this_month' => '1month', 'this_week' => '7day'];
        $data = $this->lastfm->call('user.gettopartists', ['period' => $map[$period] ?? 'overall', 'limit' => $limit]);
        $raw = $data['topartists']['artist'] ?? [];
        if (isset($raw['name'])) {
            $raw = [$raw];
        }

        $artists = [];
        foreach ($raw as $a) {
            $artists[] = ['name' => $a['name'] ?? '', 'playcount' => (int) ($a['playcount'] ?? 0)];
        }

        return ['available' => true, 'period' => $period, 'source' => 'live', 'artists' => $artists];
    }

    private function topTracks(array $args): array
    {
        [$period, $tz] = $this->periodAndTz($args);
        $limit = max(1, min(200, (int) ($args['limit'] ?? 20)));

        $source = 'local';
        $tracks = $this->library->tracksForPeriod($period, $limit, $tz);
        if ($tracks === null) {
            $source = 'live';
            $tracks = $this->lastfm->getTracksForUiPeriod($period, $limit, $tz);
        }

        $result = [];
        foreach ($tracks as $t) {
            $result[] = [
                'name'      => $t['name'] ?? '',
                'artist'    => $t['artist']['name'] ?? '',
                'playcount' => (int) ($t['playcount'] ?? 0),
            ];
        }

        return ['available' => true, 'period' => $period, 'source' => $source, 'tracks' => $result];
    }

    private function genreBreakdown(array $args): array
    {
        [$period, $tz] = $this->periodAndTz($args);
        $spotifyConfigured = !empty($this->config['spotify_client_id']) && !empty($this->config['spotify_client_secret']);
        $onlySpotify = array_key_exists('only_spotify_genres', $args) ? (bool) $args['only_spotify_genres'] : $spotifyConfigured;

        $source = 'local';
        $genres = $this->library->genresForUiPeriod($period, $tz, $onlySpotify);
        if ($genres === null) {
            $source = 'live';
            $genres = $this->lastfm->getGenresForUiPeriod(
                $period,
                (int) ($this->config['genre_artist_limit'] ?? 20),
                (int) ($this->config['genre_limit'] ?? 8),
                $tz,
                $onlySpotify
            );
        }

        return ['available' => true, 'period' => $period, 'source' => $source, 'genres' => $genres];
    }
}
