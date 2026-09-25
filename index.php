<?php

require __DIR__ . '/lib/LastFm.php';
require __DIR__ . '/lib/VersionCheck.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$configFile = __DIR__ . '/config.php';
$configMissing = !is_file($configFile);
$config = $configMissing ? [] : require $configFile;

$config += [
    'app_name'         => 'Last.fm Dashboard',
    'poll_interval_ms' => 15000,
    'cache_ttl'        => 60,
    'top_period'       => 'overall',
    'top_limit'        => 8,
    'trend_limit'      => 8,
    'recent_limit'     => 5,
    'genre_artist_limit' => 20,
    'genre_limit'      => 8,
    'version'          => '0.0.0',
    'github_repo'      => '',
    'update_check_branch' => 'main',
    'update_check_ttl' => 3600,
];

$needsSetup = $configMissing
    || empty($config['api_key']) || $config['api_key'] === 'YOUR_LASTFM_API_KEY'
    || empty($config['username']) || $config['username'] === 'YOUR_LASTFM_USERNAME';

$nowPlaying = null;
$topTracks = [];
$trending = [];
$genres = [];
$apiError = false;

if (!$needsSetup) {
    $lastfm = new LastFm($config['api_key'], $config['username'], (int) $config['cache_ttl']);

    $recent = $lastfm->getRecentTracks(1);
    $recentTrack = $recent['recenttracks']['track'][0] ?? null;

    if ($recentTrack) {
        $artist = $recentTrack['artist']['#text'] ?? ($recentTrack['artist']['name'] ?? '');
        $album = $recentTrack['album']['#text'] ?? '';
        $trackArt = LastFm::bestImage($recentTrack['image'] ?? []);
        $albumArt = $lastfm->getAlbumArt($artist, $album) ?: $trackArt;

        $nowPlaying = [
            'now_playing' => ($recentTrack['@attr']['nowplaying'] ?? '') === 'true',
            'name'        => $recentTrack['name'] ?? '',
            'artist'      => $artist,
            'album'       => $album,
            'image'       => $trackArt,
            'track_art'   => $trackArt,
            'album_art'   => $albumArt,
        ];
    } else {
        $apiError = true;
    }

    $top = $lastfm->getTopTracks($config['top_period'], (int) $config['top_limit']);
    $topTracks = $top['toptracks']['track'] ?? [];

    $trending = $lastfm->getWeeklyTrackChart((int) $config['trend_limit']);

    $genres = $lastfm->getTopGenres(
        $config['top_period'],
        (int) $config['genre_artist_limit'],
        (int) $config['genre_limit']
    );
}

$maxTopPlaycount = max(array_map(fn($t) => (int) ($t['playcount'] ?? 0), $topTracks ?: [['playcount' => 1]]));
$maxTrendPlaycount = max(array_map(fn($t) => (int) ($t['playcount'] ?? 0), $trending ?: [['playcount' => 1]]));

$periodLabels = [
    'overall' => 'All time',
    '7day'    => 'Past 7 days',
    '1month'  => 'Past month',
    '3month'  => 'Past 3 months',
    '6month'  => 'Past 6 months',
    '12month' => 'Past year',
];

$versionInfo = ['local' => null, 'remote' => null, 'up_to_date' => null, 'compare_url' => null, 'error' => null];
if (!empty($config['github_repo'])) {
    $versionCheck = new VersionCheck(
        $config['github_repo'],
        $config['update_check_branch'] ?? 'main',
        __DIR__,
        (int) ($config['update_check_ttl'] ?? 3600)
    );
    $versionInfo = $versionCheck->check();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($config['app_name']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>

<div class="bg">
    <div class="bg-layer" data-bg-a></div>
    <div class="bg-layer" data-bg-b></div>
</div>
<div class="bg-scrim"></div>

<div class="wrap">
    <header class="site-header">
        <h1><?= e($config['app_name']) ?></h1>
        <span class="updated" data-updated></span>
    </header>

    <?php if ($needsSetup): ?>
        <div class="error-banner">
            <strong>Setup needed:</strong> copy <code>config.sample.php</code> to <code>config.php</code>
            and fill in your Last.fm <code>api_key</code> and <code>username</code>.
        </div>
    <?php elseif ($apiError): ?>
        <div class="error-banner">
            Couldn't reach the Last.fm API, or no scrobbles were found for
            <strong><?= e($config['username']) ?></strong>. Check the username and API key in
            <code>config.php</code>.
        </div>
    <?php endif; ?>

    <?php $heroInitial = strtoupper(substr($nowPlaying['name'] ?? '?', 0, 1)); ?>
    <section class="now-playing">
        <div class="art-group">
            <div class="art-tile">
                <span class="art-tile-fallback" data-track-art-fallback
                      style="<?= empty($nowPlaying['track_art']) ? '' : 'display:none' ?>"><?= e($heroInitial) ?></span>
                <img data-track-art-img src="<?= e($nowPlaying['track_art'] ?? '') ?>" alt="Track art"
                     style="<?= empty($nowPlaying['track_art']) ? 'display:none' : '' ?>">
                <span class="art-label">Track</span>
            </div>
            <div class="art-tile">
                <span class="art-tile-fallback" data-album-art-fallback
                      style="<?= empty($nowPlaying['album_art']) ? '' : 'display:none' ?>"><?= e($heroInitial) ?></span>
                <img data-album-art-img src="<?= e($nowPlaying['album_art'] ?? '') ?>" alt="Album art"
                     style="<?= empty($nowPlaying['album_art']) ? 'display:none' : '' ?>">
                <span class="art-label">Album</span>
            </div>
        </div>
        <div class="info">
            <div class="status-badge" data-status-badge>
                <?= ($nowPlaying['now_playing'] ?? false)
                    ? '<span class="eq"><span></span><span></span><span></span></span> Now scrobbling'
                    : 'Last played' ?>
            </div>
            <p class="track-name" data-track-name><?= e($nowPlaying['name'] ?? 'No recent tracks') ?></p>
            <p class="track-artist" data-track-artist><?= e($nowPlaying['artist'] ?? '') ?></p>
            <p class="track-album" data-track-album><?= e($nowPlaying['album'] ?? '') ?></p>
        </div>
    </section>

    <div class="panels">
        <section class="panel">
            <h2>Favourite Tracks &middot; <?= e($periodLabels[$config['top_period']] ?? $config['top_period']) ?></h2>
            <?php if (empty($topTracks)): ?>
                <p class="empty-state">No top tracks yet.</p>
            <?php else: ?>
                <ol class="track-list">
                    <?php foreach ($topTracks as $i => $t):
                        $playcount = (int) ($t['playcount'] ?? 0);
                        $pct = max(4, round($playcount / $maxTopPlaycount * 100));
                        $art = $lastfm->getTrackArt($t['artist']['name'] ?? '', $t['name'] ?? '')
                            ?: LastFm::bestImage($t['image'] ?? []);
                        $initial = strtoupper(substr($t['name'] ?? '?', 0, 1));
                    ?>
                    <li class="track-row">
                        <span class="rank"><?= $i + 1 ?></span>
                        <span class="thumb">
                            <?php if ($art): ?>
                                <img src="<?= e($art) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <?= e($initial) ?>
                            <?php endif; ?>
                        </span>
                        <span class="meta">
                            <div class="name"><?= e($t['name'] ?? '') ?></div>
                            <div class="artist"><?= e($t['artist']['name'] ?? '') ?></div>
                        </span>
                        <span class="count">
                            <?= number_format($playcount) ?> plays
                            <div class="bar"><div class="bar-fill" style="width: <?= $pct ?>%"></div></div>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Trending &middot; This Week</h2>
            <?php if (empty($trending)): ?>
                <p class="empty-state">Not enough scrobbles this week yet.</p>
            <?php else: ?>
                <ol class="track-list">
                    <?php foreach ($trending as $i => $t):
                        $playcount = (int) ($t['playcount'] ?? 0);
                        $pct = max(4, round($playcount / $maxTrendPlaycount * 100));
                        $art = $lastfm->getTrackArt($t['artist']['#text'] ?? '', $t['name'] ?? '')
                            ?: LastFm::bestImage($t['image'] ?? []);
                        $initial = strtoupper(substr($t['name'] ?? '?', 0, 1));
                    ?>
                    <li class="track-row">
                        <span class="rank"><?= $i + 1 ?></span>
                        <span class="thumb">
                            <?php if ($art): ?>
                                <img src="<?= e($art) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <?= e($initial) ?>
                            <?php endif; ?>
                        </span>
                        <span class="meta">
                            <div class="name"><?= e($t['name'] ?? '') ?></div>
                            <div class="artist"><?= e($t['artist']['#text'] ?? '') ?></div>
                        </span>
                        <span class="count">
                            <?= number_format($playcount) ?> plays
                            <div class="bar"><div class="bar-fill" style="width: <?= $pct ?>%"></div></div>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel panel-wide">
        <h2>Genre Breakdown &middot; <?= e($periodLabels[$config['top_period']] ?? $config['top_period']) ?></h2>
        <?php if (empty($genres)): ?>
            <p class="empty-state">Not enough tagged artists yet.</p>
        <?php else: ?>
            <div class="genre-bar">
                <?php foreach ($genres as $i => $g):
                    $color = $g['name'] === 'Other' ? 'rgba(255,255,255,0.15)' : 'hsl(' . fmod($i * 137.508, 360) . ', 65%, 55%)';
                ?>
                    <div class="genre-segment" style="width: <?= $g['pct'] ?>%; background: <?= $color ?>"
                         title="<?= e($g['name'] . ' — ' . $g['pct'] . '%') ?>"></div>
                <?php endforeach; ?>
            </div>
            <ul class="genre-legend">
                <?php foreach ($genres as $i => $g):
                    $color = $g['name'] === 'Other' ? 'rgba(255,255,255,0.15)' : 'hsl(' . fmod($i * 137.508, 360) . ', 65%, 55%)';
                ?>
                    <li class="genre-legend-item">
                        <span class="genre-swatch" style="background: <?= $color ?>"></span>
                        <span class="genre-name"><?= e($g['name']) ?></span>
                        <span class="genre-pct"><?= $g['pct'] ?>%</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <footer class="site-footer">
        <?php if (!$needsSetup): ?>
            <div>Data from <a href="https://www.last.fm/user/<?= e($config['username']) ?>" target="_blank" rel="noopener">last.fm/user/<?= e($config['username']) ?></a></div>
        <?php endif; ?>
        <div class="version-line">
            <?= e('lastfm-dash v' . ($config['version'] ?? '0.0.0')) ?>
            <?php if (empty($config['github_repo'])): ?>
                &middot; <span class="version-muted">update check disabled</span>
            <?php elseif ($versionInfo['error']): ?>
                &middot; <span class="version-muted"><?= e($versionInfo['error']) ?></span>
            <?php elseif ($versionInfo['up_to_date']): ?>
                &middot; <span class="version-ok">up to date</span> (<?= e($versionInfo['local']) ?>)
            <?php else: ?>
                &middot; <a class="version-update" href="<?= e($versionInfo['compare_url']) ?>" target="_blank" rel="noopener">update available</a>
                (<?= e($versionInfo['local']) ?> &rarr; <?= e($versionInfo['remote']) ?>)
            <?php endif; ?>
        </div>
    </footer>
</div>

<script>
window.APP_CONFIG = { pollIntervalMs: <?= (int) $config['poll_interval_ms'] ?> };
window.INITIAL_TRACK = <?= json_encode($nowPlaying) ?>;
</script>
<script src="assets/app.js?v=<?= (int) @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
