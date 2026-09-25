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

// Cache slightly shorter than the browser's poll interval so every poll gets
// fresh data, while still de-duplicating any near-simultaneous requests.
$pollSeconds = max(1, (int) ($config['poll_interval_ms'] ?? 10000) / 1000);
$lastfm = new LastFm($config['api_key'], $config['username'], max(3, $pollSeconds - 2));
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
    'stats'       => LastFm::formatLifetimeStats($lastfm->getInfo()),
]);
