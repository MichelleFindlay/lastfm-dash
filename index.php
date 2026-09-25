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
require __DIR__ . '/lib/ListenLinks.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The app's own version, tracked in git via a plain VERSION file rather
 * than config.php — config.php is gitignored and user-managed, so it's the
 * wrong place for something that describes the codebase itself, bumped by
 * whoever cuts a release rather than by each individual install.
 */
function appVersion(): string
{
    $versionFile = __DIR__ . '/VERSION';

    return is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';
}

/**
 * Inner markup (no wrapping <svg>) for a small set of Lucide icons
 * (ISC-licensed, ~ lucide.dev), used to give each widget card and lifetime
 * stat a quick visual identifier. Kept as plain strings rather than fetched
 * at request time so the page has no runtime dependency on an icon CDN.
 */
const ICONS = [
    'clock'         => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'activity'      => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'route'         => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
    'tent'          => '<path d="M3.5 21 14 3"/><path d="M20.5 21 10 3"/><path d="M15.5 21 12 15l-3.5 6"/><path d="M2 21h20"/>',
    'cloud-sun'     => '<path d="M12 2v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="M20 12h2"/><path d="m19.07 4.93-1.41 1.41"/><path d="M15.947 12.65a4 4 0 0 0-5.925-4.128"/><path d="M13 22H7a5 5 0 1 1 4.9-6H13a3 3 0 0 1 0 6Z"/>',
    'heart-pulse'   => '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/><path d="M3.22 13H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>',
    'sprout'        => '<path d="M14 9.536V7a4 4 0 0 1 4-4h1.5a.5.5 0 0 1 .5.5V5a4 4 0 0 1-4 4 4 4 0 0 0-4 4c0 2 1 3 1 5a5 5 0 0 1-1 3"/><path d="M4 9a5 5 0 0 1 8 4 5 5 0 0 1-8-4"/><path d="M5 21h14"/>',
    'telescope'     => '<path d="m10.065 12.493-6.18 1.318a.934.934 0 0 1-1.108-.702l-.537-2.15a1.07 1.07 0 0 1 .691-1.265l13.504-4.44"/><path d="m13.56 11.747 4.332-.924"/><path d="m16 21-3.105-6.21"/><path d="M16.485 5.94a2 2 0 0 1 1.455-2.425l1.09-.272a1 1 0 0 1 1.212.727l1.515 6.06a1 1 0 0 1-.727 1.213l-1.09.272a2 2 0 0 1-2.425-1.455z"/><path d="m6.158 8.633 1.114 4.456"/><path d="m8 21 3.105-6.21"/><circle cx="12" cy="13" r="2"/>',
    'disc-3'        => '<circle cx="12" cy="12" r="10"/><path d="M6 12c0-1.7.7-3.2 1.8-4.2"/><circle cx="12" cy="12" r="2"/><path d="M18 12c0 1.7-.7 3.2-1.8 4.2"/>',
    'trending-up'   => '<path d="M16 7h6v6"/><path d="m22 7-8.5 8.5-5-5L2 17"/>',
    'mic-2'         => '<path d="m11 7.601-5.994 8.19a1 1 0 0 0 .1 1.298l.817.818a1 1 0 0 0 1.314.087L15.09 12"/><path d="M16.5 21.174C15.5 20.5 14.372 20 13 20c-2.058 0-3.928 2.356-6 2-2.072-.356-2.775-3.369-1.5-4.5"/><circle cx="16" cy="7" r="5"/>',
    'disc'          => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="2"/>',
    'music'         => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
    'calendar-days' => '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M8 13h.01"/><path d="M12 13h.01"/><path d="M16 13h.01"/><path d="M8 17h.01"/><path d="M12 17h.01"/><path d="M16 17h.01"/>',
];

function renderIcon(string $name, string $class): string
{
    if (!isset(ICONS[$name])) {
        return '';
    }

    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ICONS[$name] . '</svg>';
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
    'github_repo'      => '',
    'update_check_ttl' => 3600,
    'avg_track_minutes'     => 3.5,
    'scrobble_sample_pages' => 200,
    'festival_artist_limit' => 20,
    'timezone'              => '',
    'bpm_track_limit'       => 50,
    'obscure_artist_sample' => 200,

    // Which timeframe each period-picker panel shows on page load:
    // all_time | this_year | this_month | this_week | today
    'favourites_default_period' => 'all_time',
    'trending_default_period'   => 'today',
    'genre_default_period'      => 'all_time',

    // Set to true once you've scheduled cron.php to run every 15 minutes
    // (see cron.php for setup) — shown as a small footer note so it's
    // visible whether background cache warming is actually in place.
    'cron_enabled' => false,
    'cron_secret'  => '',

    // Quick-listen links (Spotify / YouTube Music) on the current track and
    // on hover over any track row. Work out of the box as plain search
    // links; adding these upgrades them to a verified direct link to the
    // exact track — see lib/ListenLinks.php for where to get each one.
    'spotify_client_id'     => '',
    'spotify_client_secret' => '',
    'youtube_api_key'       => '',
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
    $listenLinks = new ListenLinks($config, __DIR__);

    $recent = $lastfm->getRecentTracks(4);
    $recentTracks = $recent['recenttracks']['track'] ?? [];
    $recentTrack = $recentTracks[0] ?? null;

    if ($recentTrack) {
        $artist = $recentTrack['artist']['#text'] ?? ($recentTrack['artist']['name'] ?? '');
        $album = $recentTrack['album']['#text'] ?? '';
        $art = LastFm::bestImage($recentTrack['image'] ?? []);
        $listen = $listenLinks->forTrack($artist, $recentTrack['name'] ?? '');

        $nowPlaying = [
            'now_playing' => ($recentTrack['@attr']['nowplaying'] ?? '') === 'true',
            'name'        => $recentTrack['name'] ?? '',
            'artist'      => $artist,
            'album'       => $album,
            'listen'      => $listen,
            'image'       => $art,
        ];
    } else {
        $apiError = true;
    }

    $previousTrackRaw = $recentTrack
        ? LastFm::findPreviousTrack($recentTracks, $artist, $recentTrack['name'] ?? '')
        : null;

    $previousTrack = null;
    if ($previousTrackRaw) {
        $previousTrack = [
            'name'   => $previousTrackRaw['name'] ?? '',
            'artist' => $previousTrackRaw['artist']['#text'] ?? ($previousTrackRaw['artist']['name'] ?? ''),
            'image'  => LastFm::bestImage($previousTrackRaw['image'] ?? []),
        ];
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
            ['key' => 'scrobbles', 'icon' => 'disc-3', 'label' => 'Scrobbles', 'value' => $statsMap['scrobbles']],
            ['key' => 'avg_day', 'icon' => 'trending-up', 'label' => 'Avg / Day', 'value' => $statsMap['avg_day']],
            ['key' => 'artists', 'icon' => 'mic-2', 'label' => 'Artists', 'value' => $statsMap['artists']],
            ['key' => 'albums', 'icon' => 'disc', 'label' => 'Albums', 'value' => $statsMap['albums']],
            ['key' => 'tracks', 'icon' => 'music', 'label' => 'Tracks', 'value' => $statsMap['tracks']],
            ['key' => 'member_since', 'icon' => 'calendar-days', 'label' => 'Member Since', 'value' => $statsMap['member_since']],
        ];
    }
}

$widgetDefs = [
    ['id' => 'listening_clock', 'icon' => 'clock', 'title' => 'Listening Clock', 'teaser' => 'When you actually listen, mapped across 24 hours'],
    ['id' => 'energy_curve', 'icon' => 'activity', 'title' => 'Energy Curve', 'teaser' => 'How your listening activity rises and falls through the week'],
    ['id' => 'distance', 'icon' => 'route', 'title' => 'Distance Listened', 'teaser' => 'Your total minutes, converted into something absurd'],
    ['id' => 'festival', 'icon' => 'tent', 'title' => 'If Your Year Were a Festival', 'teaser' => 'Your top artists, billed as a festival lineup'],
    ['id' => 'mood', 'icon' => 'cloud-sun', 'title' => 'Mood Weather', 'teaser' => "This month's emotional forecast"],
    ['id' => 'bpm', 'icon' => 'heart-pulse', 'title' => 'BPM Average', 'teaser' => 'Your heart rate, if music were a pulse'],
    ['id' => 'before_famous', 'icon' => 'sprout', 'title' => 'Before They Were Famous', 'teaser' => "Your favourites Last.fm listeners haven't caught onto yet"],
    ['id' => 'obscurity', 'icon' => 'telescope', 'title' => 'Obscurity Index', 'teaser' => 'How mainstream your top artists really are, by the numbers'],
];

$uiPeriodLabels = [
    'all_time'   => 'All Time',
    'this_year'  => 'This Year',
    'this_month' => 'This Month',
    'this_week'  => 'This Week',
    'today'      => 'Today',
];

$versionInfo = ['installed' => appVersion(), 'latest' => null, 'up_to_date' => null, 'release_url' => null, 'error' => null];
if (!empty($config['github_repo'])) {
    $versionCheck = new VersionCheck(
        $config['github_repo'],
        appVersion(),
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
        <div class="art-tile">
            <span class="art-tile-fallback" data-art-fallback
                  style="<?= empty($nowPlaying['image']) ? '' : 'display:none' ?>"><?= e($heroInitial) ?></span>
            <img data-art-img src="<?= e($nowPlaying['image'] ?? '') ?>" alt="Album art"
                 style="<?= empty($nowPlaying['image']) ? 'display:none' : '' ?>">
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
            <div class="listen-links" data-listen-links>
                <?php $listen = $nowPlaying['listen'] ?? null; ?>
                <a class="listen-link listen-spotify" data-listen-spotify
                   href="<?= e($listen['spotify']['url'] ?? '') ?>" target="_blank" rel="noopener"
                   title="<?= !empty($listen['spotify']['verified']) ? 'Listen on Spotify' : 'Search on Spotify' ?>"
                   style="<?= empty($listen['spotify']['url']) ? 'display:none' : '' ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="currentColor" d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                    </svg>
                    <span class="listen-label"><?= !empty($listen['spotify']['verified']) ? 'Listen' : 'Search' ?></span>
                </a>
                <a class="listen-link listen-youtube" data-listen-youtube
                   href="<?= e($listen['youtube']['url'] ?? '') ?>" target="_blank" rel="noopener"
                   title="<?= !empty($listen['youtube']['verified']) ? 'Listen on YouTube Music' : 'Search on YouTube Music' ?>"
                   style="<?= empty($listen['youtube']['url']) ? 'display:none' : '' ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="currentColor" d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z"/>
                    </svg>
                    <span class="listen-label"><?= !empty($listen['youtube']['verified']) ? 'Listen' : 'Search' ?></span>
                </a>
            </div>
        </div>
        <?php $prevInitial = strtoupper(substr($previousTrack['name'] ?? '?', 0, 1)); ?>
        <div class="prev-track" data-prev-track style="<?= $previousTrack ? '' : 'display:none' ?>">
            <div class="prev-track-thumb">
                <span class="prev-track-thumb-fallback" data-prev-art-fallback
                      style="<?= empty($previousTrack['image']) ? '' : 'display:none' ?>"><?= e($prevInitial) ?></span>
                <img data-prev-art-img src="<?= e($previousTrack['image'] ?? '') ?>" alt=""
                     style="<?= empty($previousTrack['image']) ? 'display:none' : '' ?>">
            </div>
            <div class="prev-track-info">
                <div class="prev-track-label">Previously played</div>
                <div class="prev-track-name" data-prev-track-name><?= e($previousTrack['name'] ?? '') ?></div>
                <div class="prev-track-artist" data-prev-track-artist><?= e($previousTrack['artist'] ?? '') ?></div>
            </div>
        </div>
    </section>

    <?php
    function renderPeriodPicker(string $group, string $active, array $labels): void
    {
        echo '<div class="period-picker" data-period-group-wrap="' . e($group) . '">';
        foreach ($labels as $code => $label) {
            $activeClass = $code === $active ? ' active' : '';
            $title = '';
            if ($code === 'this_year') {
                $title = ' title="Last.fm\'s rolling 12-month window, not calendar year"';
            } elseif ($code === 'this_week') {
                $title = ' title="Last.fm\'s rolling 7-day window, not calendar week"';
            }
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
            // Last.fm's API occasionally lists an image URL that 404s on its
            // own CDN, so fall back to the letter placeholder on load
            // failure rather than showing a broken image.
            $thumb = $art
                ? '<img src="' . e($art) . '" alt="" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'\';">'
                    . '<span class="thumb-fallback" style="display:none">' . e($initial) . '</span>'
                : e($initial);
            echo '<li class="track-row" data-artist="' . e($artistName) . '" data-track="' . e($t['name'] ?? '') . '">'
                . '<span class="rank">' . ($i + 1) . '</span>'
                . '<span class="thumb">' . $thumb . '</span>'
                . '<span class="meta"><div class="name">' . e($t['name'] ?? '') . '</div><div class="artist">' . e($artistName) . '</div></span>'
                . '<span class="count">' . number_format($playcount) . ' plays<div class="bar"><div class="bar-fill" style="width: ' . $pct . '%"></div></div></span>'
                . '<span class="listen-links-hover" data-listen-links-hover></span>'
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
                    <?= renderIcon($w['icon'], 'widget-card-icon') ?>
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
                        <?= renderIcon($stat['icon'], 'stat-icon') ?>
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
                <a class="version-gh-link" href="https://github.com/<?= e($config['github_repo']) ?>" target="_blank" rel="noopener">
                    <svg class="github-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                        <path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z"></path>
                    </svg>
                    lastfm-dash
                </a>
            <?php else: ?>
                lastfm-dash
            <?php endif; ?>
            v<?= e(appVersion()) ?>
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
        <?php if (!empty($config['cron_enabled'])): ?>
            <div class="cron-line" title="cron.php is scheduled to refresh widget and stats caches every 15 minutes">
                &#8635; Background refresh active
            </div>
        <?php endif; ?>
    </footer>
</div>

<script>
window.APP_CONFIG = { pollIntervalMs: <?= (int) $config['poll_interval_ms'] ?> };
window.INITIAL_TRACK = <?= json_encode($nowPlaying) ?>;
</script>
<script src="assets/app.js?v=<?= (int) @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
