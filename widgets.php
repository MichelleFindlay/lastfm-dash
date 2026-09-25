<?php

/**
 * JSON endpoint for the insight widget popups. Each widget is computed
 * lazily on first click (rather than on every page load) since a couple of
 * them paginate through scrobble history, which is too slow to do on every
 * request. The full response is then cached for an hour — see
 * cachedWidgetOutput() below — and rebuilt from scratch on the next request
 * once that expires.
 */

header('Content-Type: application/json');

// Some widgets sample deep into your library (each extra artist/track costs
// its own external API call, cached afterward but not on a cold miss), so
// the default 30s execution limit some hosts set isn't always enough.
// Silently no-ops on hosts where set_time_limit is disabled.
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

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
        $period = LastFm::validUiPeriod($_GET['period'] ?? '');
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
        $period = LastFm::validUiPeriod($_GET['period'] ?? '');
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

/**
 * Widget results are cached for an hour as a single unit — separately from
 * (and on top of) the finer-grained caching each widget already does
 * internally for its own expensive sub-calls. On expiry the stale file is
 * deleted outright and regenerated fresh on this request, rather than left
 * around to be merely overwritten.
 */
function cachedWidgetOutput(string $id, callable $compute): array
{
    $ttl = 3600;
    $params = $_GET;
    ksort($params);
    $cacheFile = __DIR__ . '/cache/widget_' . $id . '_' . md5(serialize($params)) . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if ($cached !== null) {
            return $cached;
        }
    }

    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }

    $result = $compute();

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($cacheFile, json_encode($result));

    return $result;
}

echo json_encode(['ok' => true, 'id' => $id, 'data' => cachedWidgetOutput($id, $handlers[$id])]);
