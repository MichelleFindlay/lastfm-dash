<?php

// The genre breakdown samples deep into your top artists on a cold cache
// (each one costs its own Last.fm call), so the default 30s execution
// limit some hosts set isn't always enough for that first page load.
// Silently no-ops on hosts where set_time_limit is disabled.
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

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
    'poll_interval_ms' => 10000,
    'cache_ttl'        => 60,
    'top_period'       => 'overall',
    'top_limit'        => 8,
    'trend_limit'      => 8,
    'recent_limit'     => 5,
    'genre_artist_limit' => 200,
    'genre_limit'      => 8,
    'version'          => '0.0.0',
    'github_repo'      => '',
    'update_check_ttl' => 3600,
    'avg_track_minutes'     => 3.5,
    'scrobble_sample_pages' => 200,
    'festival_artist_limit' => 20,
    'timezone'              => '',
    'bpm_track_limit'       => 50,
    'obscure_artist_sample' => 200,

    // Which timeframe each period-picker panel shows on page load:
    // all_time | this_year | this_month | today
    'favourites_default_period' => 'all_time',
    'trending_default_period'   => 'today',
    'genre_default_period'      => 'all_time',
];

$needsSetup = $configMissing
    || empty($config['api_key']) || $config['api_key'] === 'YOUR_LASTFM_API_KEY'
    || empty($config['username']) || $config['username'] === 'YOUR_LASTFM_USERNAME';

$lastfm = null;
$nowPlaying = null;
$topTracks = [];
$trending = [];
$genres = [];
$lifetimeStats = [];
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

    $tz = LastFm::resolveTimezone($config['timezone'] ?? '');

    $activeFavouritesPeriod = LastFm::validUiPeriod($config['favourites_default_period'] ?? 'all_time', 'all_time');
    $activeTrendingPeriod   = LastFm::validUiPeriod($config['trending_default_period'] ?? 'today', 'today');
    $activeGenrePeriod      = LastFm::validUiPeriod($config['genre_default_period'] ?? 'all_time', 'all_time');

    $topTracks = $lastfm->getTracksForUiPeriod($activeFavouritesPeriod, (int) $config['top_limit'], $tz);
    $trending = $lastfm->getTracksForUiPeriod($activeTrendingPeriod, (int) $config['trend_limit'], $tz);

    $genres = $lastfm->getGenresForUiPeriod(
        $activeGenrePeriod,
        (int) $config['genre_artist_limit'],
        (int) $config['genre_limit'],
        $tz
    );

    $statsMap = LastFm::formatLifetimeStats($lastfm->getInfo());
    if ($statsMap) {
        $lifetimeStats = [
            ['key' => 'scrobbles', 'label' => 'Scrobbles', 'value' => $statsMap['scrobbles']],
            ['key' => 'avg_day', 'label' => 'Avg / Day', 'value' => $statsMap['avg_day']],
            ['key' => 'artists', 'label' => 'Artists', 'value' => $statsMap['artists']],
            ['key' => 'albums', 'label' => 'Albums', 'value' => $statsMap['albums']],
            ['key' => 'tracks', 'label' => 'Tracks', 'value' => $statsMap['tracks']],
            ['key' => 'member_since', 'label' => 'Member Since', 'value' => $statsMap['member_since']],
        ];
    }
}

$widgetDefs = [
    ['id' => 'listening_clock', 'title' => 'Listening Clock', 'teaser' => 'When you actually listen, mapped across 24 hours'],
    ['id' => 'energy_curve', 'title' => 'Energy Curve', 'teaser' => 'How your listening activity rises and falls through the week'],
    ['id' => 'distance', 'title' => 'Distance Listened', 'teaser' => 'Your total minutes, converted into something absurd'],
    ['id' => 'festival', 'title' => 'If Your Year Were a Festival', 'teaser' => 'Your top artists, billed as a festival lineup'],
    ['id' => 'mood', 'title' => 'Mood Weather', 'teaser' => "This month's emotional forecast"],
    ['id' => 'bpm', 'title' => 'BPM Average', 'teaser' => 'Your heart rate, if music were a pulse'],
    ['id' => 'before_famous', 'title' => 'Before They Were Famous', 'teaser' => "Your favourites Last.fm listeners haven't caught onto yet"],
    ['id' => 'obscurity', 'title' => 'Obscurity Index', 'teaser' => 'How mainstream your top artists really are, by the numbers'],
];

$uiPeriodLabels = [
    'all_time'   => 'All Time',
    'this_year'  => 'This Year',
    'this_month' => 'This Month',
    'today'      => 'Today',
];

$versionInfo = ['installed' => $config['version'] ?? '0.0.0', 'latest' => null, 'up_to_date' => null, 'release_url' => null, 'error' => null];
if (!empty($config['github_repo'])) {
    $versionCheck = new VersionCheck(
        $config['github_repo'],
        $config['version'] ?? '0.0.0',
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

    <?php
    function renderPeriodPicker(string $group, string $active, array $labels): void
    {
        echo '<div class="period-picker" data-period-group-wrap="' . e($group) . '">';
        foreach ($labels as $code => $label) {
            $activeClass = $code === $active ? ' active' : '';
            $title = $code === 'this_year' ? ' title="Last.fm\'s rolling 12-month window, not calendar year"' : '';
            echo '<button type="button" class="period-btn' . $activeClass . '" data-period-group="' . e($group) . '" data-period="' . e($code) . '"' . $title . '>' . e($label) . '</button>';
        }
        echo '</div>';
    }

    function renderTrackListMarkup(array $tracks, ?LastFm $lastfm, string $emptyMessage): void
    {
        if (empty($tracks)) {
            echo '<p class="empty-state">' . e($emptyMessage) . '</p>';
            return;
        }

        $maxPlaycount = max(array_map(fn($t) => (int) ($t['playcount'] ?? 0), $tracks ?: [['playcount' => 1]]));
        echo '<ol class="track-list">';
        foreach ($tracks as $i => $t) {
            $playcount = (int) ($t['playcount'] ?? 0);
            $pct = max(4, round($playcount / $maxPlaycount * 100));
            $artistName = $t['artist']['name'] ?? '';
            $art = $lastfm->getTrackArt($artistName, $t['name'] ?? '') ?: LastFm::bestImage($t['image'] ?? []);
            $initial = strtoupper(substr($t['name'] ?? '?', 0, 1));
            $thumb = $art
                ? '<img src="' . e($art) . '" alt="" loading="lazy">'
                : e($initial);
            echo '<li class="track-row">'
                . '<span class="rank">' . ($i + 1) . '</span>'
                . '<span class="thumb">' . $thumb . '</span>'
                . '<span class="meta"><div class="name">' . e($t['name'] ?? '') . '</div><div class="artist">' . e($artistName) . '</div></span>'
                . '<span class="count">' . number_format($playcount) . ' plays<div class="bar"><div class="bar-fill" style="width: ' . $pct . '%"></div></div></span>'
                . '</li>';
        }
        echo '</ol>';
    }
    ?>

    <div class="panels">
        <section class="panel">
            <div class="panel-header-row">
                <h2>Favourite Tracks</h2>
                <?php if (!$needsSetup): ?>
                    <?php renderPeriodPicker('favourites', $activeFavouritesPeriod, $uiPeriodLabels); ?>
                <?php endif; ?>
            </div>
            <div data-period-content="favourites">
                <?php renderTrackListMarkup($topTracks, $lastfm, 'No tracks for this period yet.'); ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header-row">
                <h2>Trending</h2>
                <?php if (!$needsSetup): ?>
                    <?php renderPeriodPicker('trending', $activeTrendingPeriod, $uiPeriodLabels); ?>
                <?php endif; ?>
            </div>
            <div data-period-content="trending">
                <?php renderTrackListMarkup($trending, $lastfm, 'No tracks for this period yet.'); ?>
            </div>
        </section>
    </div>

    <section class="panel panel-wide">
        <div class="panel-header-row">
            <h2>Genre Breakdown</h2>
            <?php if (!$needsSetup): ?>
                <?php renderPeriodPicker('genre', $activeGenrePeriod, $uiPeriodLabels); ?>
            <?php endif; ?>
        </div>
        <div data-period-content="genre">
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
        </div>
    </section>

    <?php if (!$needsSetup): ?>
        <div class="widget-grid">
            <?php foreach ($widgetDefs as $w): ?>
                <button type="button" class="widget-card" data-widget-id="<?= e($w['id']) ?>">
                    <span class="widget-card-title"><?= e($w['title']) ?></span>
                    <span class="widget-card-teaser"><?= e($w['teaser']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="modal-overlay" data-modal-overlay hidden>
            <div class="modal" role="dialog" aria-modal="true">
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                <div class="modal-body" data-modal-body></div>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel panel-wide">
        <h2>Lifetime Stats</h2>
        <?php if (empty($lifetimeStats)): ?>
            <p class="empty-state">Stats unavailable.</p>
        <?php else: ?>
            <div class="stats-row">
                <?php foreach ($lifetimeStats as $stat): ?>
                    <div class="stat-item">
                        <div class="stat-value" data-stat="<?= e($stat['key']) ?>"><?= e($stat['value']) ?></div>
                        <div class="stat-label"><?= e($stat['label']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <footer class="site-footer">
        <?php if (!$needsSetup): ?>
            <div class="lastfm-profile-line">
                <a href="https://www.last.fm/user/<?= e($config['username']) ?>" target="_blank" rel="noopener">
                    <svg class="lastfm-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="currentColor" d="M10.584 17.21l-.88-2.392s-1.43 1.594-3.573 1.594c-1.897 0-3.244-1.649-3.244-4.288 0-3.382 1.704-4.591 3.381-4.591 2.42 0 3.189 1.567 3.849 3.574l.88 2.749c.88 2.666 2.529 4.81 7.285 4.81 3.409 0 5.718-1.044 5.718-3.793 0-2.227-1.265-3.381-3.63-3.931l-1.758-.385c-1.21-.275-1.567-.77-1.567-1.595 0-.934.742-1.484 1.952-1.484 1.32 0 2.034.495 2.144 1.677l2.749-.33c-.22-2.474-1.924-3.492-4.729-3.492-2.474 0-4.893.935-4.893 3.932 0 1.87.907 3.051 3.189 3.601l1.87.44c1.402.33 1.869.907 1.869 1.704 0 1.017-.99 1.43-2.86 1.43-2.776 0-3.93-1.457-4.59-3.464l-.907-2.75c-1.155-3.573-2.997-4.893-6.653-4.893C2.144 5.333 0 7.89 0 12.233c0 4.18 2.144 6.434 5.993 6.434 3.106 0 4.591-1.457 4.591-1.457z"></path>
                    </svg>
                    <span><?= e($config['username']) ?></span>
                </a>
            </div>
        <?php endif; ?>
        <div class="version-line">
            <?php if (!empty($config['github_repo'])): ?>
                <a class="version-gh-link" href="https://github.com/<?= e($config['github_repo']) ?>" target="_blank" rel="noopener" aria-label="View on GitHub">
                    <svg class="github-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                        <path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z"></path>
                    </svg>
                </a>
            <?php endif; ?>
            <?= e('lastfm-dash v' . ($config['version'] ?? '0.0.0')) ?>
            <?php if (empty($config['github_repo'])): ?>
                &middot; <span class="version-muted">update check disabled</span>
            <?php elseif ($versionInfo['error']): ?>
                &middot; <span class="version-muted"><?= e($versionInfo['error']) ?></span>
            <?php elseif ($versionInfo['up_to_date']): ?>
                &middot; <span class="version-ok">up to date</span>
            <?php else: ?>
                &middot; <a class="version-update" href="<?= e($versionInfo['release_url']) ?>" target="_blank" rel="noopener">Update available: v<?= e($versionInfo['latest']) ?></a>
                — you're on v<?= e($versionInfo['installed']) ?>
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
