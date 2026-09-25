<?php

/**
 * JSON endpoint for the hover-triggered "listen on Spotify / YouTube Music"
 * links on Favourite Tracks / Trending rows. Looked up lazily per track on
 * first hover (rather than for every row on page load) since there can be
 * 16+ rows and most will never be hovered — see assets/app.js.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/ListenLinks.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing config.php']);
    exit;
}

$config = require $configFile;

$artist = trim((string) ($_GET['artist'] ?? ''));
$track = trim((string) ($_GET['track'] ?? ''));

if ($artist === '' || $track === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'artist and track are required']);
    exit;
}

$listenLinks = new ListenLinks($config, __DIR__);

echo json_encode(['ok' => true, 'links' => $listenLinks->forTrack($artist, $track)]);
