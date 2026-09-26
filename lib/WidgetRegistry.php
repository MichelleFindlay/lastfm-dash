<?php

/**
 * Builds the id => compute-closure map for every widget endpoint. Shared
 * between widgets.php (serves a widget on demand) and cron.php (pre-warms
 * them on a schedule) so the two definitions can't drift out of sync.
 */
class WidgetRegistry
{
    /**
     * Widget ids that take no extra query params — the set cron.php
     * pre-warms directly. "tracks" is excluded: it's parameterized by
     * period and panel, so pre-warming every combination is a separate
     * concern from "the insight widgets". "genre" is also parameterized by
     * period, but cron.php pre-warms it separately below, since it's the
     * main page's slowest cold-cache path (one artist.gettoptags call per
     * top artist, per period).
     */
    public const SIMPLE_IDS = [
        'listening_clock',
        'energy_curve',
        'distance',
        'festival',
        'mood',
        'bpm',
        'before_famous',
        'obscurity',
    ];

    public static function handlers(LastFm $lastfm, Widgets $widgets, array $config, LibrarySync $library): array
    {
        return [
            'listening_clock' => fn() => $widgets->listeningClock(),
            'energy_curve'    => fn() => $widgets->energyCurve(),
            'distance'        => fn() => $widgets->distanceListened(),
            'festival'        => fn() => $widgets->festivalPoster(),
            'mood'            => fn() => $widgets->moodWeather(),
            'bpm'             => fn() => $widgets->bpmPulse(),
            'before_famous'   => fn() => $widgets->beforeFamous(),
            'obscurity'       => fn() => $widgets->obscurityIndex(),
            'genre'           => function () use ($lastfm, $library, $config) {
                $period = LastFm::validUiPeriod($_GET['period'] ?? '');
                $tz = LastFm::resolveTimezone($config['timezone'] ?? '');
                $artistLimit = (int) ($config['genre_artist_limit'] ?? 20);
                $genreLimit = (int) ($config['genre_limit'] ?? 8);
                $spotifyAvailable = !empty($config['spotify_client_id']) && !empty($config['spotify_client_secret']);

                $genres = $library->genresForUiPeriod($period, $tz, $spotifyAvailable)
                    ?? $lastfm->getGenresForUiPeriod($period, $artistLimit, $genreLimit, $tz, $spotifyAvailable);

                return ['available' => !empty($genres), 'period' => $period, 'genres' => $genres];
            },
            'tracks' => function () use ($lastfm, $library, $config) {
                $period = LastFm::validUiPeriod($_GET['period'] ?? '');
                $panel = ($_GET['panel'] ?? '') === 'trending' ? 'trending' : 'favourites';
                $limit = (int) ($panel === 'trending' ? ($config['trend_limit'] ?? 8) : ($config['top_limit'] ?? 8));
                $tz = LastFm::resolveTimezone($config['timezone'] ?? '');

                $tracks = $library->tracksForPeriod($period, $limit, $tz)
                    ?? $lastfm->getTracksForUiPeriod($period, $limit, $tz);
                $maxPlaycount = max(array_map(fn($t) => (int) ($t['playcount'] ?? 0), $tracks ?: [['playcount' => 1]]));

                $items = [];
                foreach ($tracks as $i => $t) {
                    $playcount = (int) ($t['playcount'] ?? 0);
                    $artistName = $t['artist']['name'] ?? '';
                    $art = $lastfm->getTrackArt($artistName, $t['name'] ?? '') ?: LastFm::bestImage($t['image'] ?? []);

                    $items[] = [
                        'rank'      => $i + 1,
                        'name'      => $t['name'] ?? '',
                        'artist'    => $artistName,
                        'playcount' => $playcount,
                        'pct'       => max(4, round($playcount / $maxPlaycount * 100)),
                        'art'       => $art,
                    ];
                }

                return ['available' => !empty($items), 'period' => $period, 'panel' => $panel, 'tracks' => $items];
            },
        ];
    }
}
