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
require __DIR__ . '/lib/LibrarySync.php';
require __DIR__ . '/lib/GrammyAwards.php';
require __DIR__ . '/lib/Certifications.php';

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
    'library_backfill_pages_per_run' => 20,
    'library_tag_backfill_per_run'   => 50,

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

    // Quick-listen links (Spotify / YouTube Music / Apple Music / Amazon
    // Music) on the current track and on hover over any track row. Work out
    // of the box as plain search links; adding these upgrades Spotify/
    // YouTube to a verified direct link to the exact track — see
    // lib/ListenLinks.php for where to get each one. Apple Music needs no
    // credentials at all; Amazon Music has no public search API, so it's
    // always a search link.
    'spotify_client_id'     => '',
    'spotify_client_secret' => '',
    'youtube_api_key'       => '',
    'youtube_daily_limit'   => 100,
    'mcp_api_key'           => '',
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
    $spotify = new Spotify($config['spotify_client_id'] ?? '', $config['spotify_client_secret'] ?? '');
    $appleMusic = new AppleMusic(__DIR__);
    $grammyAwards = new GrammyAwards(__DIR__);
    $certifications = new Certifications(__DIR__, $config['github_repo'] ?? '');
    $tz = LastFm::resolveTimezone($config['timezone'] ?? '');
    $library = new LibrarySync($lastfm, $config['username']);

    $recent = $lastfm->getRecentTracks(4);
    $recentTracks = $recent['recenttracks']['track'] ?? [];
    $recentTrack = $recentTracks[0] ?? null;

    if ($recentTrack) {
        $artist = $recentTrack['artist']['#text'] ?? ($recentTrack['artist']['name'] ?? '');
        $album = $recentTrack['album']['#text'] ?? '';
        $art = $spotify->resolveTrackArt($lastfm, $artist, $recentTrack['name'] ?? '', $recentTrack['image'] ?? [], $appleMusic);
        $listen = $listenLinks->forTrack($artist, $recentTrack['name'] ?? '');

        $nowPlaying = [
            'now_playing' => ($recentTrack['@attr']['nowplaying'] ?? '') === 'true',
            'name'        => $recentTrack['name'] ?? '',
            'artist'      => $artist,
            'album'       => $album,
            'listen'      => $listen,
            'image'       => $art,
            'track_stats' => $lastfm->getTrackStats($artist, $recentTrack['name'] ?? ''),
            'insights'    => $library->trackInsights($artist, $recentTrack['name'] ?? '', $tz),
            'loved'       => ($recentTrack['loved'] ?? '0') === '1',
            'grammy'      => $grammyAwards->findAward($artist, $recentTrack['name'] ?? ''),
            'certs'       => $certifications->find($artist, $recentTrack['name'] ?? ''),
            'explicit'    => $spotify->isExplicit($artist, $recentTrack['name'] ?? '', $appleMusic),
        ];
    } else {
        $apiError = true;
    }

    $previousTrackRaw = $recentTrack
        ? LastFm::findPreviousTrack($recentTracks, $artist, $recentTrack['name'] ?? '')
        : null;

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
            'certs'       => $certifications->find($previousArtist, $previousTrackRaw['name'] ?? ''),
            'explicit'    => $spotify->isExplicit($previousArtist, $previousTrackRaw['name'] ?? '', $appleMusic),
            'listen'      => $listenLinks->forTrack($previousArtist, $previousTrackRaw['name'] ?? ''),
        ];
    }

    $activeFavouritesPeriod = LastFm::validUiPeriod($config['favourites_default_period'] ?? 'all_time', 'all_time');
    $activeTrendingPeriod   = LastFm::validUiPeriod($config['trending_default_period'] ?? 'today', 'today');
    $activeGenrePeriod      = LastFm::validUiPeriod($config['genre_default_period'] ?? 'all_time', 'all_time');

    $spotifyAvailable = !empty($config['spotify_client_id']) && !empty($config['spotify_client_secret']);

    $topTracks = $library->tracksForPeriod($activeFavouritesPeriod, (int) $config['top_limit'], $tz)
        ?? $lastfm->getTracksForUiPeriod($activeFavouritesPeriod, (int) $config['top_limit'], $tz);
    $trending = $library->tracksForPeriod($activeTrendingPeriod, (int) $config['trend_limit'], $tz)
        ?? $lastfm->getTracksForUiPeriod($activeTrendingPeriod, (int) $config['trend_limit'], $tz);

    $genres = $library->genresForUiPeriod($activeGenrePeriod, $tz, $spotifyAvailable)
        ?? $lastfm->getGenresForUiPeriod(
            $activeGenrePeriod,
            (int) $config['genre_artist_limit'],
            (int) $config['genre_limit'],
            $tz,
            $spotifyAvailable
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
        <div class="art-hover">
            <div class="art-tile">
                <span class="art-tile-fallback" data-art-fallback
                      style="<?= empty($nowPlaying['image']) ? '' : 'display:none' ?>"><?= e($heroInitial) ?></span>
                <img data-art-img src="<?= e($nowPlaying['image'] ?? '') ?>" alt="Album art"
                     style="<?= empty($nowPlaying['image']) ? 'display:none' : '' ?>">
            </div>
            <div class="art-tooltip" data-art-tooltip style="<?= $nowPlaying['name'] ?? '' ? '' : 'display:none' ?>">
                <div class="art-tooltip-track" data-tooltip-track><?= e($nowPlaying['name'] ?? '') ?></div>
                <div class="art-tooltip-artist" data-tooltip-artist><?= e($nowPlaying['artist'] ?? '') ?></div>
                <div class="art-tooltip-album" data-tooltip-album style="<?= empty($nowPlaying['album']) ? 'display:none' : '' ?>"><?= e($nowPlaying['album'] ?? '') ?></div>
                <?= renderTooltipStats($nowPlaying['track_stats'] ?? null, true, 'data-tooltip-stats', 'data-tooltip-you') ?>
                <?= renderTooltipInsights($nowPlaying['insights'] ?? null, 'data-tooltip-rank', 'data-tooltip-first', 'data-tooltip-recency') ?>
            </div>
        </div>
        <div class="info">
            <div class="status-badge" data-status-badge>
                <?= ($nowPlaying['now_playing'] ?? false)
                    ? '<span class="eq"><span></span><span></span><span></span></span> Now scrobbling'
                    : 'Last played' ?>
            </div>
            <p class="track-name"><span class="track-name-text" data-track-name><?= e($nowPlaying['name'] ?? 'No recent tracks') ?></span><?= renderLovedHeart($nowPlaying['loved'] ?? false, 'data-loved-heart') ?><?= renderGrammyBadge($nowPlaying['grammy'] ?? null, 'data-grammy-badge') ?><?= renderCertBadge($nowPlaying['certs'] ?? null, 'data-cert-badge') ?><?= renderExplicitBadge($nowPlaying['explicit'] ?? false, 'data-explicit-badge') ?></p>
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
                <a class="listen-link listen-apple" data-listen-apple
                   href="<?= e($listen['apple']['url'] ?? '') ?>" target="_blank" rel="noopener"
                   title="<?= !empty($listen['apple']['verified']) ? 'Listen on Apple Music' : 'Search on Apple Music' ?>"
                   style="<?= empty($listen['apple']['url']) ? 'display:none' : '' ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="currentColor" d="M23.994 6.124a9.23 9.23 0 0 0-.24-2.19c-.317-1.31-1.062-2.31-2.18-3.043A5.022 5.022 0 0 0 19.952.17 9.077 9.077 0 0 0 18.14 0H5.86l-.126.002c-.517.005-1.03.038-1.539.133-1.172.219-2.19.72-3.02 1.567C.414 2.616-.01 3.638 0 4.906c0 .064.014.128.014.192v13.814c0 .157.004.315.012.472.027.59.095 1.175.27 1.744.42 1.37 1.302 2.335 2.63 2.912.57.248 1.168.37 1.788.44.44.05.882.058 1.325.058h12.374c.51 0 1.014-.034 1.516-.11 1.202-.182 2.24-.67 3.052-1.59.65-.738 1.014-1.606 1.154-2.566.07-.483.093-.97.096-1.457.002-.12.008-.24.008-.36V6.124zM12.14 15.63c-.054.957-.724 1.682-1.68 1.788-.986.11-1.853-.512-2.058-1.48-.172-.82.287-1.69 1.09-2.05.26-.117.534-.15.814-.15.047 0 .093.003.14.005l.004-7.015c0-.286.102-.414.38-.47 1.396-.283 2.79-.567 4.187-.848.336-.067.49.047.49.39v6.58c0 .61-.013 1.22.002 1.828.028 1.102-.804 1.973-1.835 1.983-.98.01-1.766-.606-1.985-1.56-.14-.606.04-1.146.47-1.57.33-.327.75-.49 1.21-.46.236.014.46.075.67.19v-5.26c-1.167.237-2.333.472-3.5.71v6.389z"/>
                    </svg>
                    <span class="listen-label"><?= !empty($listen['apple']['verified']) ? 'Listen' : 'Search' ?></span>
                </a>
                <a class="listen-link listen-amazon" data-listen-amazon
                   href="<?= e($listen['amazon']['url'] ?? '') ?>" target="_blank" rel="noopener"
                   title="Search on Amazon Music"
                   style="<?= empty($listen['amazon']['url']) ? 'display:none' : '' ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="currentColor" d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>
                    </svg>
                    <span class="listen-label">Search</span>
                </a>
                <a class="listen-link listen-video" data-listen-video
                   href="<?= e($listen['video']['url'] ?? '') ?>" target="_blank" rel="noopener"
                   title="Watch the music video on YouTube"
                   style="<?= empty($listen['video']['url']) ? 'display:none' : '' ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="currentColor" d="M10 15l5.19-3L10 9v6m11.56-7.83c.13.47.22 1.1.28 1.9.07.8.1 1.49.1 2.09L22 12c0 2.19-.16 3.8-.44 4.83-.25.9-.83 1.48-1.73 1.73-.47.13-1.33.22-2.65.28-1.3.07-2.49.1-3.59.1L12 19c-4.19 0-6.8-.16-7.83-.44-.9-.25-1.48-.83-1.73-1.73-.13-.47-.22-1.1-.28-1.9-.07-.8-.1-1.49-.1-2.09L2 12c0-2.19.16-3.8.44-4.83.25-.9.83-1.48 1.73-1.73.47-.13 1.33-.22 2.65-.28 1.3-.07 2.49-.1 3.59-.1L12 5c4.19 0 6.8.16 7.83.44.9.25 1.48.83 1.73 1.73z"/>
                    </svg>
                    <span class="listen-label">Video</span>
                </a>
            </div>
        </div>
        <?php $prevInitial = strtoupper(substr($previousTrack['name'] ?? '?', 0, 1)); ?>
        <div class="prev-track" data-prev-track style="<?= $previousTrack ? '' : 'display:none' ?>">
            <div class="art-hover">
                <div class="prev-track-thumb">
                    <span class="prev-track-thumb-fallback" data-prev-art-fallback
                          style="<?= empty($previousTrack['image']) ? '' : 'display:none' ?>"><?= e($prevInitial) ?></span>
                    <img data-prev-art-img src="<?= e($previousTrack['image'] ?? '') ?>" alt=""
                         style="<?= empty($previousTrack['image']) ? 'display:none' : '' ?>">
                </div>
                <div class="art-tooltip" data-prev-art-tooltip style="<?= $previousTrack ? '' : 'display:none' ?>">
                    <div class="art-tooltip-track" data-prev-tooltip-track><?= e($previousTrack['name'] ?? '') ?></div>
                    <div class="art-tooltip-artist" data-prev-tooltip-artist><?= e($previousTrack['artist'] ?? '') ?></div>
                    <div class="art-tooltip-album" data-prev-tooltip-album style="<?= empty($previousTrack['album']) ? 'display:none' : '' ?>"><?= e($previousTrack['album'] ?? '') ?></div>
                    <?= renderTooltipStats($previousTrack['track_stats'] ?? null, true, 'data-prev-tooltip-stats', 'data-prev-tooltip-you') ?>
                    <?= renderTooltipInsights($previousTrack['insights'] ?? null, 'data-prev-tooltip-rank', 'data-prev-tooltip-first', 'data-prev-tooltip-recency') ?>
                </div>
            </div>
            <div class="prev-track-info">
                <div class="prev-track-label">Previously played</div>
                <div class="prev-track-name"><span class="track-name-text" data-prev-track-name><?= e($previousTrack['name'] ?? '') ?></span><?= renderLovedHeart($previousTrack['loved'] ?? false, 'data-prev-loved-heart') ?><?= renderGrammyBadge($previousTrack['grammy'] ?? null, 'data-prev-grammy-badge') ?><?= renderCertBadge($previousTrack['certs'] ?? null, 'data-prev-cert-badge') ?><?= renderExplicitBadge($previousTrack['explicit'] ?? false, 'data-prev-explicit-badge') ?></div>
                <div class="prev-track-artist" data-prev-track-artist><?= e($previousTrack['artist'] ?? '') ?></div>
                <?= renderMiniListenLinks($previousTrack['listen'] ?? null, 'data-prev-listen-links') ?>
            </div>
        </div>
    </section>

    <?php
    function renderPeriodPicker(string $group, string $active, array $labels): void
    {
        echo '<div class="period-picker" data-period-group-wrap="' . e($group) . '">';
        foreach ($labels as $code => $label) {
            $activeClass = $code === $active ? ' active' : '';
            echo '<button type="button" class="period-btn' . $activeClass . '" data-period-group="' . e($group) . '" data-period="' . e($code) . '">' . e($label) . '</button>';
        }
        echo '</div>';
    }

    /**
     * Shared hover-tooltip stats line(s): global listeners/scrobbles always,
     * plus (for contexts that don't already show a playcount elsewhere,
     * like the now-playing hero) your own all-time plays and duration.
     *
     * $statsAttr/$youAttr (e.g. 'data-tooltip-stats') make the two lines
     * always render — hidden via inline style when empty rather than
     * omitted — so JS can find and update them on the next poll; leave
     * both blank (the track-row case, never JS-updated in place) to just
     * omit a line entirely when it has nothing to show.
     */
    function renderTooltipStats(?array $stats, bool $includeYourPlays, string $statsAttr = '', string $youAttr = ''): string
    {
        $stats = $stats ?? ['listeners' => 0, 'playcount' => 0, 'userplaycount' => 0, 'duration' => 0];

        $statsLine = ($stats['listeners'] > 0 || $stats['playcount'] > 0)
            ? number_format($stats['listeners']) . ' listeners · ' . number_format($stats['playcount']) . ' scrobbles'
            : '';

        $youParts = [];
        if ($includeYourPlays) {
            if ($stats['userplaycount'] > 0) {
                $youParts[] = number_format($stats['userplaycount']) . ' of your plays';
            }
            if ($stats['duration'] > 0) {
                $youParts[] = sprintf('%d:%02d', intdiv($stats['duration'], 60), $stats['duration'] % 60);
            }
        }
        $youLine = implode(' · ', $youParts);

        $html = '';
        if ($statsAttr !== '' || $statsLine !== '') {
            $html .= '<div class="art-tooltip-stats"' . ($statsAttr !== '' ? ' ' . $statsAttr : '')
                . ($statsLine === '' ? ' style="display:none"' : '') . '>' . e($statsLine) . '</div>';
        }
        if ($includeYourPlays && ($youAttr !== '' || $youLine !== '')) {
            $html .= '<div class="art-tooltip-you"' . ($youAttr !== '' ? ' ' . $youAttr : '')
                . ($youLine === '' ? ' style="display:none"' : '') . '>' . e($youLine) . '</div>';
        }

        return $html;
    }

    /**
     * The tooltip's "insights" lines — all-time rank, first-scrobbled date,
     * and a recency/streak line (see LibrarySync::trackInsights()) — each
     * independently omitted when $insights doesn't have it (local history
     * doesn't cover it honestly yet), same hidden-vs-omitted convention as
     * renderTooltipStats().
     */
    function renderTooltipInsights(?array $insights, string $rankAttr = '', string $firstAttr = '', string $recencyAttr = ''): string
    {
        $insights = $insights ?? ['track_rank' => null, 'artist_rank' => null, 'first_scrobbled' => null, 'recency' => null];

        $rankParts = [];
        if ($insights['track_rank']) {
            $rankParts[] = '#' . number_format($insights['track_rank']['rank']) . ' track all-time';
        }
        if ($insights['artist_rank']) {
            $rankParts[] = '#' . number_format($insights['artist_rank']['rank']) . ' artist all-time';
        }
        $rankLine = implode(' · ', $rankParts);

        $firstLine = $insights['first_scrobbled'] ? 'First scrobbled ' . date('j M Y', $insights['first_scrobbled']) : '';
        $recencyLine = $insights['recency'] ? ucfirst($insights['recency']) : '';

        $html = '';
        if ($rankAttr !== '' || $rankLine !== '') {
            $html .= '<div class="art-tooltip-rank"' . ($rankAttr !== '' ? ' ' . $rankAttr : '')
                . ($rankLine === '' ? ' style="display:none"' : '') . '>' . e($rankLine) . '</div>';
        }
        if ($firstAttr !== '' || $firstLine !== '') {
            $html .= '<div class="art-tooltip-first"' . ($firstAttr !== '' ? ' ' . $firstAttr : '')
                . ($firstLine === '' ? ' style="display:none"' : '') . '>' . e($firstLine) . '</div>';
        }
        if ($recencyAttr !== '' || $recencyLine !== '') {
            $html .= '<div class="art-tooltip-recency"' . ($recencyAttr !== '' ? ' ' . $recencyAttr : '')
                . ($recencyLine === '' ? ' style="display:none"' : '') . '>' . e($recencyLine) . '</div>';
        }

        return $html;
    }

    /**
     * The little heart icon marking a track as "loved" on Last.fm. Always
     * rendered (hidden via inline style when not loved, not omitted) when
     * $attr is given, so JS can toggle it in place on the next poll;
     * omitted entirely for the static track-row case.
     */
    function renderLovedHeart(bool $loved, string $attr = ''): string
    {
        if (!$loved && $attr === '') {
            return '';
        }

        return '<span class="loved-heart-badge"' . ($attr !== '' ? ' ' . $attr : '') . ($loved ? '' : ' style="display:none"') . '>'
            . '<svg class="loved-heart" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><title>Loved on Last.fm</title>'
            . '<path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg></span>';
    }

    /**
     * The little trophy icon marking a track as a Grammy Award winner (see
     * lib/GrammyAwards.php). Always rendered (hidden via inline style when
     * there's no award, not omitted) when $attr is given, so JS can toggle
     * it in place on the next poll; omitted entirely for the static
     * track-row case.
     */
    function renderGrammyBadge(?array $award, string $attr = ''): string
    {
        if (!$award && $attr === '') {
            return '';
        }

        $title = $award ? 'Grammy Award: ' . $award['category'] . ' (' . $award['year'] . ')' : '';

        return '<span class="grammy-badge"' . ($attr !== '' ? ' ' . $attr : '') . ($award ? '' : ' style="display:none"')
            . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>'
            . '<svg class="grammy-icon" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><title>' . e($title) . '</title>'
            . '<path fill="currentColor" d="M7 2a1 1 0 0 0-1 1v2H4a1 1 0 0 0-1 1v2c0 2.21 1.79 4 4 4 .34 1.6 1.63 2.86 3.25 3.17V18H9a1 1 0 0 0-1 1v2a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1h-1.25v-2.83c1.62-.31 2.91-1.57 3.25-3.17 2.21 0 4-1.79 4-4V6a1 1 0 0 0-1-1h-2V3a1 1 0 0 0-1-1H7zM5 7h1v1.83A2.5 2.5 0 0 1 5 7zm13 0v1.83A2.5 2.5 0 0 0 19 7h-1z"/></svg></span>';
    }

    /**
     * The row of small round service icons (same look as the hover links on
     * track rows) under the previously-played track. The wrapper is always
     * rendered so JS can refill it in place on the next poll.
     */
    function renderMiniListenLinks(?array $listen, string $attr): string
    {
        $services = [
            'spotify' => ['Spotify', 'M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z'],
            'youtube' => ['YouTube Music', 'M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z'],
            'apple'   => ['Apple Music', 'M23.994 6.124a9.23 9.23 0 0 0-.24-2.19c-.317-1.31-1.062-2.31-2.18-3.043A5.022 5.022 0 0 0 19.952.17 9.077 9.077 0 0 0 18.14 0H5.86l-.126.002c-.517.005-1.03.038-1.539.133-1.172.219-2.19.72-3.02 1.567C.414 2.616-.01 3.638 0 4.906c0 .064.014.128.014.192v13.814c0 .157.004.315.012.472.027.59.095 1.175.27 1.744.42 1.37 1.302 2.335 2.63 2.912.57.248 1.168.37 1.788.44.44.05.882.058 1.325.058h12.374c.51 0 1.014-.034 1.516-.11 1.202-.182 2.24-.67 3.052-1.59.65-.738 1.014-1.606 1.154-2.566.07-.483.093-.97.096-1.457.002-.12.008-.24.008-.36V6.124zM12.14 15.63c-.054.957-.724 1.682-1.68 1.788-.986.11-1.853-.512-2.058-1.48-.172-.82.287-1.69 1.09-2.05.26-.117.534-.15.814-.15.047 0 .093.003.14.005l.004-7.015c0-.286.102-.414.38-.47 1.396-.283 2.79-.567 4.187-.848.336-.067.49.047.49.39v6.58c0 .61-.013 1.22.002 1.828.028 1.102-.804 1.973-1.835 1.983-.98.01-1.766-.606-1.985-1.56-.14-.606.04-1.146.47-1.57.33-.327.75-.49 1.21-.46.236.014.46.075.67.19v-5.26c-1.167.237-2.333.472-3.5.71v6.389z'],
            'amazon'  => ['Amazon Music', 'M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z'],
            'video'   => ['', 'M10 15l5.19-3L10 9v6m11.56-7.83c.13.47.22 1.1.28 1.9.07.8.1 1.49.1 2.09L22 12c0 2.19-.16 3.8-.44 4.83-.25.9-.83 1.48-1.73 1.73-.47.13-1.33.22-2.65.28-1.3.07-2.49.1-3.59.1L12 19c-4.19 0-6.8-.16-7.83-.44-.9-.25-1.48-.83-1.73-1.73-.13-.47-.22-1.1-.28-1.9-.07-.8-.1-1.49-.1-2.09L2 12c0-2.19.16-3.8.44-4.83.25-.9.83-1.48 1.73-1.73.47-.13 1.33-.22 2.65-.28 1.3-.07 2.49-.1 3.59-.1L12 5c4.19 0 6.8.16 7.83.44.9.25 1.48.83 1.73 1.73z'],
        ];

        $html = '<div class="mini-listen-links" ' . $attr . '>';
        foreach ($services as $service => [$serviceName, $path]) {
            $link = $listen[$service] ?? null;
            if (empty($link['url'])) {
                continue;
            }
            $html .= '<a class="listen-icon listen-' . $service . '" href="' . e($link['url']) . '" target="_blank" rel="noopener"'
                . ' title="' . ($service === 'video' ? 'Watch the music video on YouTube' : ((!empty($link['verified']) ? 'Listen on ' : 'Search on ') . e($serviceName))) . '">'
                . '<svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path fill="currentColor" d="' . $path . '"/></svg></a>';
        }

        return $html . '</div>';
    }

    /**
     * The small boxed "E" marking a track as explicit (see
     * Spotify::isExplicit()). Same hidden-vs-omitted convention as
     * renderLovedHeart() / renderGrammyBadge().
     */
    function renderExplicitBadge(bool $explicit, string $attr = ''): string
    {
        if (!$explicit && $attr === '') {
            return '';
        }

        return '<span class="explicit-badge" title="Explicit" aria-label="Explicit"' . ($attr !== '' ? ' ' . $attr : '')
            . ($explicit ? '' : ' style="display:none"') . '>E</span>';
    }

    /**
     * The record-disc icon for a track's highest UK (BPI) / US (RIAA) sales
     * certification (see lib/Certifications.php), coloured by tier, with
     * the multiplier for multi-Platinum/Diamond and each region's award
     * and unit count in its tooltip. Same hidden-vs-omitted convention as
     * renderGrammyBadge().
     */
    function renderCertBadge(?array $certs, string $attr = ''): string
    {
        if (!$certs && $attr === '') {
            return '';
        }

        $title = $certs ? implode("\n", $certs['lines']) : '';
        $multiplier = ($certs['multiplier'] ?? 1) > 1 ? $certs['multiplier'] . '×' : '';

        return '<span class="cert-badge' . ($certs ? ' cert-' . e($certs['tier']) : '') . '"' . ($attr !== '' ? ' ' . $attr : '')
            . ($certs ? '' : ' style="display:none"') . ' title="' . e($title) . '" aria-label="' . e(str_replace("\n", '; ', $title)) . '">'
            . '<svg class="cert-icon" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true">'
            . '<path fill="currentColor" fill-rule="evenodd" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 6.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7zm0 2.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg>'
            . '<span class="cert-multiplier">' . e($multiplier) . '</span></span>';
    }

    function renderTrackListMarkup(array $tracks, ?LastFm $lastfm, string $emptyMessage, ?LibrarySync $library = null, ?DateTimeZone $tz = null, ?Spotify $spotify = null, ?AppleMusic $appleMusic = null, ?GrammyAwards $grammyAwards = null, ?Certifications $certifications = null): void
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
            if ($art === '' && $spotify) {
                $match = $spotify->searchTrack($artistName, $t['name'] ?? '');
                $art = $match['art'] ?? '';
            }
            if ($art === '' && $appleMusic) {
                $appleMatch = $appleMusic->searchTrack($artistName, $t['name'] ?? '');
                $art = $appleMatch['art'] ?? '';
            }
            // Reuses the same cached track.getInfo lookup getTrackArt() just
            // made above, so this costs nothing extra — top-tracks lists
            // don't carry album info or listen-count stats themselves.
            $album = $lastfm->getTrackAlbum($artistName, $t['name'] ?? '');
            $stats = $lastfm->getTrackStats($artistName, $t['name'] ?? '');
            $insights = ($library && $tz) ? $library->trackInsights($artistName, $t['name'] ?? '', $tz) : null;
            $award = $grammyAwards ? $grammyAwards->findAward($artistName, $t['name'] ?? '') : null;
            $explicit = $spotify ? $spotify->isExplicit($artistName, $t['name'] ?? '', $appleMusic) : false;
            $certs = $certifications ? $certifications->find($artistName, $t['name'] ?? '') : null;
            $initial = strtoupper(substr($t['name'] ?? '?', 0, 1));
            // Last.fm's API occasionally lists an image URL that 404s on its
            // own CDN, so fall back to the letter placeholder on load
            // failure rather than showing a broken image.
            $thumb = $art
                ? '<img src="' . e($art) . '" alt="" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'\';">'
                    . '<span class="thumb-fallback" style="display:none">' . e($initial) . '</span>'
                : e($initial);
            $tooltip = '<div class="art-tooltip">'
                . '<div class="art-tooltip-track">' . e($t['name'] ?? '') . '</div>'
                . '<div class="art-tooltip-artist">' . e($artistName) . '</div>'
                . ($album !== '' ? '<div class="art-tooltip-album">' . e($album) . '</div>' : '')
                . renderTooltipStats($stats, false)
                . renderTooltipInsights($insights)
                . '</div>';
            echo '<li class="track-row" data-artist="' . e($artistName) . '" data-track="' . e($t['name'] ?? '') . '">'
                . '<span class="rank">' . ($i + 1) . '</span>'
                . '<span class="art-hover"><span class="thumb">' . $thumb . '</span>' . $tooltip . '</span>'
                . '<span class="meta"><div class="name"><span class="track-name-text">' . e($t['name'] ?? '') . '</span>' . renderLovedHeart($stats['loved']) . renderGrammyBadge($award) . renderCertBadge($certs) . renderExplicitBadge($explicit) . '</div><div class="artist">' . e($artistName) . '</div></span>'
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
                <?php renderTrackListMarkup($topTracks, $lastfm, 'No tracks for this period yet.', $library ?? null, $tz ?? null, $spotify ?? null, $appleMusic ?? null, $grammyAwards ?? null, $certifications ?? null); ?>
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
                <?php renderTrackListMarkup($trending, $lastfm, 'No tracks for this period yet.', $library ?? null, $tz ?? null, $spotify ?? null, $appleMusic ?? null, $grammyAwards ?? null, $certifications ?? null); ?>
            </div>
        </section>
    </div>

    <section class="panel panel-wide">
        <div class="panel-header-row">
            <h2>Genre Breakdown</h2>
            <?php if (!$needsSetup): ?>
                <div class="genre-controls">
                    <?php renderPeriodPicker('genre', $activeGenrePeriod, $uiPeriodLabels); ?>
                    <label class="genre-threshold-label">
                        Show
                        <select data-genre-threshold>
                            <option value="0">all genres</option>
                            <option value="1" selected>above 1%</option>
                            <option value="2">above 2%</option>
                            <option value="5">above 5%</option>
                        </select>
                    </label>
                </div>
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
                        <li class="genre-legend-item" data-pct="<?= $g['pct'] ?>">
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
        <div class="powered-by-line">
            <span>Powered by</span>
            <a href="https://www.last.fm/api" target="_blank" rel="noopener" title="Last.fm" aria-label="Last.fm">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M10.584 17.21l-.88-2.392s-1.43 1.594-3.573 1.594c-1.897 0-3.244-1.649-3.244-4.288 0-3.382 1.704-4.591 3.381-4.591 2.42 0 3.189 1.567 3.849 3.574l.88 2.749c.88 2.666 2.529 4.81 7.285 4.81 3.409 0 5.718-1.044 5.718-3.793 0-2.227-1.265-3.381-3.63-3.931l-1.758-.385c-1.21-.275-1.567-.77-1.567-1.595 0-.934.742-1.484 1.952-1.484 1.32 0 2.034.495 2.144 1.677l2.749-.33c-.22-2.474-1.924-3.492-4.729-3.492-2.474 0-4.893.935-4.893 3.932 0 1.87.907 3.051 3.189 3.601l1.87.44c1.402.33 1.869.907 1.869 1.704 0 1.017-.99 1.43-2.86 1.43-2.776 0-3.93-1.457-4.59-3.464l-.907-2.75c-1.155-3.573-2.997-4.893-6.653-4.893C2.144 5.333 0 7.89 0 12.233c0 4.18 2.144 6.434 5.993 6.434 3.106 0 4.591-1.457 4.591-1.457z"></path></svg>
            </a>
            <a href="https://developer.spotify.com" target="_blank" rel="noopener" title="Spotify" aria-label="Spotify">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"></path></svg>
            </a>
            <a href="https://performance-partners.apple.com/search-api" target="_blank" rel="noopener" title="Apple Music" aria-label="Apple Music">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M23.994 6.124a9.23 9.23 0 00-.24-2.19c-.317-1.31-1.062-2.31-2.18-3.043a5.022 5.022 0 00-1.877-.726 10.496 10.496 0 00-1.564-.15c-.04-.003-.083-.01-.124-.013H5.986c-.152.01-.303.017-.455.026-.747.043-1.49.123-2.193.4-1.336.53-2.3 1.452-2.865 2.78-.192.448-.292.925-.363 1.408-.056.392-.088.785-.1 1.18 0 .032-.007.062-.01.093v12.223c.01.14.017.283.027.424.05.815.154 1.624.497 2.373.65 1.42 1.738 2.353 3.234 2.801.42.127.856.187 1.293.228.555.053 1.11.06 1.667.06h11.03a12.5 12.5 0 001.57-.1c.822-.106 1.596-.35 2.295-.81a5.046 5.046 0 001.88-2.207c.186-.42.293-.87.37-1.324.113-.675.138-1.358.137-2.04-.002-3.8 0-7.595-.003-11.393zm-6.423 3.99v5.712c0 .417-.058.827-.244 1.206-.29.59-.76.962-1.388 1.14-.35.1-.706.157-1.07.173-.95.045-1.773-.6-1.943-1.536a1.88 1.88 0 011.038-2.022c.323-.16.67-.25 1.018-.324.378-.082.758-.153 1.134-.24.274-.063.457-.23.51-.516a.904.904 0 00.02-.193c0-1.815 0-3.63-.002-5.443a.725.725 0 00-.026-.185c-.04-.15-.15-.243-.304-.234-.16.01-.318.035-.475.066-.76.15-1.52.303-2.28.456l-2.325.47-1.374.278c-.016.003-.032.01-.048.013-.277.077-.377.203-.39.49-.002.042 0 .086 0 .13-.002 2.602 0 5.204-.003 7.805 0 .42-.047.836-.215 1.227-.278.64-.77 1.04-1.434 1.233-.35.1-.71.16-1.075.172-.96.036-1.755-.6-1.92-1.544-.14-.812.23-1.685 1.154-2.075.357-.15.73-.232 1.108-.31.287-.06.575-.116.86-.177.383-.083.583-.323.6-.714v-.15c0-2.96 0-5.922.002-8.882 0-.123.013-.25.042-.37.07-.285.273-.448.546-.518.255-.066.515-.112.774-.165.733-.15 1.466-.296 2.2-.444l2.27-.46c.67-.134 1.34-.27 2.01-.403.22-.043.442-.088.663-.106.31-.025.523.17.554.482.008.073.012.148.012.223.002 1.91.002 3.822 0 5.732z"></path></svg>
            </a>
            <a href="https://developers.deezer.com" target="_blank" rel="noopener" title="Deezer" aria-label="Deezer">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M.693 10.024c.381 0 .693-1.256.693-2.807 0-1.55-.312-2.807-.693-2.807C.312 4.41 0 5.666 0 7.217s.312 2.808.693 2.808ZM21.038 1.56c-.364 0-.684.805-.91 2.096C19.765 1.446 19.184 0 18.526 0c-.78 0-1.464 2.036-1.784 5-.312-2.158-.788-3.536-1.325-3.536-.745 0-1.386 2.704-1.62 6.472-.442-1.932-1.083-3.145-1.793-3.145s-1.35 1.213-1.793 3.145c-.242-3.76-.874-6.463-1.628-6.463-.537 0-1.013 1.378-1.325 3.535C6.938 2.036 6.262 0 5.474 0c-.658 0-1.247 1.447-1.602 3.665-.217-1.291-.546-2.105-.91-2.105-.675 0-1.221 2.807-1.221 6.272 0 3.466.546 6.273 1.221 6.273.277 0 .537-.476.736-1.273.32 2.928.996 4.938 1.776 4.938.606 0 1.143-1.204 1.507-3.11.251 3.622.875 6.195 1.602 6.195.46 0 .875-1.023 1.187-2.677C10.142 21.6 11 24 12.004 24c1.005 0 1.863-2.4 2.235-5.822.312 1.654.727 2.677 1.186 2.677.728 0 1.352-2.573 1.603-6.195.364 1.906.9 3.11 1.507 3.11.78 0 1.455-2.01 1.775-4.938.208.797.46 1.273.737 1.273.675 0 1.22-2.807 1.22-6.273-.008-3.457-.553-6.272-1.23-6.272ZM23.307 10.024c.381 0 .693-1.256.693-2.807 0-1.55-.312-2.807-.693-2.807-.381 0-.693 1.256-.693 2.807s.312 2.808.693 2.808Z"></path></svg>
            </a>
            <a href="https://developers.google.com/youtube/v3" target="_blank" rel="noopener" title="YouTube" aria-label="YouTube">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"></path></svg>
            </a>
            <a href="https://musicbrainz.org/doc/MusicBrainz_API" target="_blank" rel="noopener" title="MusicBrainz" aria-label="MusicBrainz">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M11.582 0L1.418 5.832v12.336L11.582 24V10.01L7.1 12.668v3.664c.01.111.01.225 0 .336-.103.435-.54.804-1 1.111-.802.537-1.752.509-2.166-.111-.413-.62-.141-1.631.666-2.168.384-.28.863-.399 1.334-.332V6.619c0-.154.134-.252.226-.308L11.582 3zm.836 0v6.162c.574.03 1.14.16 1.668.387a2.225 2.225 0 0 0 1.656-.717 1.02 1.02 0 1 1 1.832-.803l.004.006a1.022 1.022 0 0 1-1.295 1.197c-.34.403-.792.698-1.297.85.34.263.641.576.891.928a1.04 1.04 0 0 1 .777.125c.768.486.568 1.657-.318 1.857-.886.2-1.574-.77-1.09-1.539.02-.03.042-.06.065-.09a3.598 3.598 0 0 0-1.436-1.166 4.142 4.142 0 0 0-1.457-.369v4.01c.855.06 1.256.493 1.555.834.227.256.356.39.578.402.323.018.568.008.806 0a5.44 5.44 0 0 1 .895.022c.94-.017 1.272-.226 1.605-.446a2.533 2.533 0 0 1 1.131-.463 1.027 1.027 0 0 1 .12-.263 1.04 1.04 0 0 1 .105-.137c.023-.025.047-.044.07-.066a4.775 4.775 0 0 1 0-2.405l-.012-.01a1.02 1.02 0 1 1 .692.272h-.057a4.288 4.288 0 0 0 0 1.877h.063a1.02 1.02 0 1 1-.545 1.883l-.047-.033a1 1 0 0 1-.352-.442 1.885 1.885 0 0 0-.814.354 3.03 3.03 0 0 1-.703.365c.757.555 1.772 1.6 2.199 2.299a1.03 1.03 0 0 1 .256-.033 1.02 1.02 0 1 1-.545 1.88l-.047-.03a1.017 1.017 0 0 1-.27-1.376.72.72 0 0 1 .051-.072c-.445-.775-2.026-2.28-2.46-2.387a4.037 4.037 0 0 0-1.31-.117c-.24.008-.513.018-.866 0-.515-.027-.783-.333-1.043-.629-.26-.296-.51-.56-1.055-.611V18.5a1.877 1.877 0 0 0 .426-.135.333.333 0 0 1 .058-.027c.56-.267 1.421-.91 2.096-2.447a1.02 1.02 0 0 1-.27-1.344 1.02 1.02 0 1 1 .915 1.54 6.273 6.273 0 0 1-1.432 2.136 1.785 1.785 0 0 1 .691.306.667.667 0 0 0 .37.168 3.31 3.31 0 0 0 .888-.222 1.02 1.02 0 0 1 1.787-.79v-.005a1.02 1.02 0 0 1-.773 1.683 1.022 1.022 0 0 1-.719-.287 3.935 3.935 0 0 1-1.168.287h-.05a1.313 1.313 0 0 1-.71-.275c-.262-.177-.51-.345-1.402-.12a2.098 2.098 0 0 1-.707.2V24l10.164-5.832V5.832zm4.154 4.904a.352.352 0 0 0-.197.639l.018.01c.163.1.378.053.484-.108v-.002a.352.352 0 0 0-.303-.539zm-4.99 1.928L7.082 9.5v2l4.5-2.668zm8.385.38a.352.352 0 0 0-.295.165v.002a.35.35 0 0 0 .096.473l.013.01a.357.357 0 0 0 .487-.108.352.352 0 0 0-.301-.541zM16.09 8.647a.352.352 0 0 0-.277.163.355.355 0 0 0 .296.54c.482 0 .463-.73-.02-.703zm3.877 2.477a.352.352 0 0 0-.295.164.35.35 0 0 0 .094.475l.015.01a.357.357 0 0 0 .485-.11.352.352 0 0 0-.3-.539zm-4.375 3.594a.352.352 0 0 0-.291.172.35.35 0 0 0-.04.265.352.352 0 1 0 .33-.437zm4.375.789a.352.352 0 0 0-.295.164v.002a.352.352 0 0 0 .094.473l.015.01a.357.357 0 0 0 .485-.108.352.352 0 0 0-.3-.54zm-2.803 2.488v.002a.347.347 0 0 0-.223.084.352.352 0 0 0 .23.62.347.347 0 0 0 .23-.085.348.348 0 0 0 .12-.24.353.353 0 0 0-.35-.38.347.347 0 0 0-.007 0Z"></path></svg>
            </a>
            <a href="https://www.mediawiki.org/wiki/API:Main_page" target="_blank" rel="noopener" title="Wikipedia" aria-label="Wikipedia">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M12.09 13.119c-.936 1.932-2.217 4.548-2.853 5.728-.616 1.074-1.127.931-1.532.029-1.406-3.321-4.293-9.144-5.651-12.409-.251-.601-.441-.987-.619-1.139-.181-.15-.554-.24-1.122-.271C.103 5.033 0 4.982 0 4.898v-.455l.052-.045c.924-.005 5.401 0 5.401 0l.051.045v.434c0 .119-.075.176-.225.176l-.564.031c-.485.029-.727.164-.727.436 0 .135.053.33.166.601 1.082 2.646 4.818 10.521 4.818 10.521l.136.046 2.411-4.81-.482-1.067-1.658-3.264s-.318-.654-.428-.872c-.728-1.443-.712-1.518-1.447-1.617-.207-.023-.313-.05-.313-.149v-.468l.06-.045h4.292l.113.037v.451c0 .105-.076.15-.227.15l-.308.047c-.792.061-.661.381-.136 1.422l1.582 3.252 1.758-3.504c.293-.64.233-.801.111-.947-.07-.084-.305-.22-.812-.24l-.201-.021c-.052 0-.098-.015-.145-.051-.045-.031-.067-.076-.067-.129v-.427l.061-.045c1.247-.008 4.043 0 4.043 0l.059.045v.436c0 .121-.059.178-.193.178-.646.03-.782.095-1.023.439-.12.186-.375.589-.646 1.039l-2.301 4.273-.065.135 2.792 5.712.17.048 4.396-10.438c.154-.422.129-.722-.064-.895-.197-.172-.346-.273-.857-.295l-.42-.016c-.061 0-.105-.014-.152-.045-.043-.029-.072-.075-.072-.119v-.436l.059-.045h4.961l.041.045v.437c0 .119-.074.18-.209.18-.648.03-1.127.18-1.443.421-.314.255-.557.616-.736 1.067 0 0-4.043 9.258-5.426 12.339-.525 1.007-1.053.917-1.503-.031-.571-1.171-1.773-3.786-2.646-5.71l.053-.036z"></path></svg>
            </a>
        </div>
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
