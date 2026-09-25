<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php is gitignored so your API key never gets committed.
 *
 * Get an API key at: https://www.last.fm/api/account/create
 */
return [
    // Required
    'api_key'  => 'YOUR_LASTFM_API_KEY',
    'username' => 'YOUR_LASTFM_USERNAME',

    // Dashboard branding
    'app_name' => 'Last.fm Dashboard',

    // How often the browser polls for now-playing updates, in milliseconds
    'poll_interval_ms' => 15000,

    // How long server-side API responses are cached, in seconds
    'cache_ttl' => 60,

    // Favourite tracks panel: overall | 7day | 1month | 3month | 6month | 12month
    'top_period' => 'overall',
    'top_limit'  => 8,

    // Trending panel: top tracks scrobbled during the current chart week
    'trend_limit' => 8,

    // Recent tracks panel (shown when nothing is currently playing)
    'recent_limit' => 5,

    // Displayed in the footer, and used as the baseline for the update check below
    'version' => '1.0.0',

    // Footer "update available" check: compares the locally checked-out git
    // commit against the latest commit on this GitHub repo/branch. Set
    // github_repo to '' to disable the check entirely.
    'github_repo'         => 'MichelleFindlay/lastfm-dash',
    'update_check_branch' => 'main',
    'update_check_ttl'    => 3600,
];
