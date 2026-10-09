<?php

/**
 * Lightweight JSON endpoint polled by the dashboard to refresh the
 * "now playing" track without reloading the page.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/LastFm.php';
require __DIR__ . '/lib/ListenLinks.php';
require __DIR__ . '/lib/LibrarySync.php';
require __DIR__ . '/lib/GrammyAwards.php';

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
$listenLinks = new ListenLinks($config, __DIR__);
$spotify = new Spotify($config['spotify_client_id'] ?? '', $config['spotify_client_secret'] ?? '');
$appleMusic = new AppleMusic(__DIR__);
$grammyAwards = new GrammyAwards(__DIR__);
$library = new LibrarySync($lastfm, $config['username']);
$tz = LastFm::resolveTimezone($config['timezone'] ?? '');
$recent = $lastfm->getRecentTracks(4);
$tracks = $recent['recenttracks']['track'] ?? [];

$track = $tracks[0] ?? null;

if (!$track) {
    echo json_encode(['ok' => false]);
    exit;
}

$isNowPlaying = ($track['@attr']['nowplaying'] ?? '') === 'true';
$artist = $track['artist']['#text'] ?? ($track['artist']['name'] ?? '');
$album = $track['album']['#text'] ?? '';
$art = $spotify->resolveTrackArt($lastfm, $artist, $track['name'] ?? '', $track['image'] ?? [], $appleMusic);
$previousTrackRaw = LastFm::findPreviousTrack($tracks, $artist, $track['name'] ?? '');

$previousTrack = null;
if ($previousTrackRaw) {
    $previousArtist = $previousTrackRaw['artist']['#text'] ?? ($previousTrackRaw['artist']['name'] ?? '');
    $previousTrack = [
        'name'        => $previousTrackRaw['name'] ?? '',
        'artist'      => $previousArtist,
        'album'       => $previousTrackRaw['album']['#text'] ?? '',
        'image'       => $spotify->resolveTrackArt($lastfm, $previousArtist, $previousTrackRaw['name'] ?? '', $previousTrackRaw['image'] ?? [], $appleMusic),
        'track_stats' => $lastfm->getTrackStats($previousArtist, $previousTrackRaw['name'] ?? ''),
        'insights'    => $library->trackInsights($previousArtist, $previousTrackRaw['name'] ?? '', $tz),
        'loved'       => ($previousTrackRaw['loved'] ?? '0') === '1',
        'grammy'      => $grammyAwards->findAward($previousArtist, $previousTrackRaw['name'] ?? ''),
    ];
}

echo json_encode([
    'ok'          => true,
    'now_playing' => $isNowPlaying,
    'name'        => $track['name'] ?? '',
    'artist'      => $artist,
    'album'       => $album,
    'image'       => $art,
    'url'         => $track['url'] ?? '',
    'date'        => $track['date']['#text'] ?? null,
    'stats'       => LastFm::formatLifetimeStats($lastfm->getInfo()),
    'listen'      => $listenLinks->forTrack($artist, $track['name'] ?? ''),
    'track_stats' => $lastfm->getTrackStats($artist, $track['name'] ?? ''),
    'insights'    => $library->trackInsights($artist, $track['name'] ?? '', $tz),
    'loved'       => ($track['loved'] ?? '0') === '1',
    'grammy'      => $grammyAwards->findAward($artist, $track['name'] ?? ''),
    'previous'    => $previousTrack,
]);
