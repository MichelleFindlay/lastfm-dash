<?php

/**
 * JSON endpoint for the "In films: ..." line on track art tooltips. Looked
 * up lazily per track on first hover (rather than for every row on page
 * load) since MusicBrainz only allows about one request per second — see
 * lib/FilmSoundtracks.php and assets/app.js.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/FilmSoundtracks.php';

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

$filmSoundtracks = new FilmSoundtracks(__DIR__, $config['github_repo'] ?? '');
$films = $filmSoundtracks->findFilms($artist, $track);

if ($films === null) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'MusicBrainz lookup failed']);
    exit;
}

echo json_encode(['ok' => true, 'films' => $films]);
