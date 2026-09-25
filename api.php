<?php

/**
 * Lightweight JSON endpoint polled by the dashboard to refresh the
 * "now playing" track without reloading the page.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/LastFm.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing config.php']);
    exit;
}

$config = require $configFile;

// Poll with a short cache TTL so "now playing" stays fresh without hammering the API.
$lastfm = new LastFm($config['api_key'], $config['username'], 10);
$recent = $lastfm->getRecentTracks(1);

$track = $recent['recenttracks']['track'][0] ?? null;

if (!$track) {
    echo json_encode(['ok' => false]);
    exit;
}

$isNowPlaying = ($track['@attr']['nowplaying'] ?? '') === 'true';
$artist = $track['artist']['#text'] ?? ($track['artist']['name'] ?? '');
$album = $track['album']['#text'] ?? '';
$trackArt = LastFm::bestImage($track['image'] ?? []);
$albumArt = $lastfm->getAlbumArt($artist, $album) ?: $trackArt;

echo json_encode([
    'ok'          => true,
    'now_playing' => $isNowPlaying,
    'name'        => $track['name'] ?? '',
    'artist'      => $artist,
    'album'       => $album,
    'image'       => $trackArt,
    'track_art'   => $trackArt,
    'album_art'   => $albumArt,
    'url'         => $track['url'] ?? '',
    'date'        => $track['date']['#text'] ?? null,
]);
