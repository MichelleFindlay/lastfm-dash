<?php

/**
 * JSON endpoint for the insight widget popups. Each widget is computed
 * lazily on first click (rather than on every page load) since a couple of
 * them paginate through scrobble history, which is too slow to do on
 * every request.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/LastFm.php';
require __DIR__ . '/lib/Widgets.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing config.php']);
    exit;
}

$config = require $configFile;
$id = $_GET['id'] ?? '';

function uiPeriod(string $value): string
{
    $valid = ['all_time', 'this_year', 'this_month', 'today'];

    return in_array($value, $valid, true) ? $value : 'all_time';
}

$lastfm = new LastFm($config['api_key'], $config['username'], (int) ($config['cache_ttl'] ?? 60));
$widgets = new Widgets($lastfm, $config);

$handlers = [
    'listening_clock' => fn() => $widgets->listeningClock(),
    'energy_curve'    => fn() => $widgets->energyCurve(),
    'distance'        => fn() => $widgets->distanceListened(),
    'festival'        => fn() => $widgets->festivalPoster(),
    'mood'            => fn() => $widgets->moodWeather(),
    'bpm'             => fn() => $widgets->bpmPulse(),
    'before_famous'   => fn() => $widgets->beforeFamous(),
    'obscurity'       => fn() => $widgets->obscurityIndex(),
    'genre'           => function () use ($lastfm, $config) {
        $period = uiPeriod($_GET['period'] ?? '');
        $tz = LastFm::resolveTimezone($config['timezone'] ?? '');

        $genres = $lastfm->getGenresForUiPeriod(
            $period,
            (int) ($config['genre_artist_limit'] ?? 20),
            (int) ($config['genre_limit'] ?? 8),
            $tz
        );

        return ['available' => !empty($genres), 'period' => $period, 'genres' => $genres];
    },
    'tracks' => function () use ($lastfm, $config) {
        $period = uiPeriod($_GET['period'] ?? '');
        $panel = ($_GET['panel'] ?? '') === 'trending' ? 'trending' : 'favourites';
        $limit = (int) ($panel === 'trending' ? ($config['trend_limit'] ?? 8) : ($config['top_limit'] ?? 8));
        $tz = LastFm::resolveTimezone($config['timezone'] ?? '');

        $tracks = $lastfm->getTracksForUiPeriod($period, $limit, $tz);
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

if (!isset($handlers[$id])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown widget']);
    exit;
}

echo json_encode(['ok' => true, 'id' => $id, 'data' => $handlers[$id]()]);
