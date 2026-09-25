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
    'poll_interval_ms' => 10000,

    // How long server-side API responses are cached, in seconds
    'cache_ttl' => 60,

    // Favourite tracks panel: overall | 7day | 1month | 3month | 6month | 12month
    'top_period' => 'overall',
    'top_limit'  => 8,

    // Trending panel: top tracks scrobbled during the current chart week
    'trend_limit' => 8,

    // Recent tracks panel (shown when nothing is currently playing)
    'recent_limit' => 5,

    // Genre breakdown: how many top artists to sample tags from, and how
    // many genres to show before lumping the rest into "Other"
    'genre_artist_limit' => 20,
    'genre_limit'        => 8,

    // Insight widgets (Listening Clock, Energy Curve, Festival Poster, Mood
    // Weather, etc. — the clickable cards below Genre Breakdown)
    'avg_track_minutes'     => 3.5,   // used to estimate total listening time (Last.fm doesn't record real durations)
    'scrobble_sample_pages' => 5,     // pages of 200 recent scrobbles sampled for time-of-day/day-of-week patterns
    'festival_artist_limit' => 12,    // artists included in the festival poster lineup
    'timezone'              => '',    // IANA tz e.g. 'Europe/London' — leave blank to use the server's default
    'bpm_track_limit'       => 15,    // top tracks sampled for BPM lookup (via Deezer's free API — Last.fm has no tempo data)
    'obscure_artist_sample' => 25,    // top artists sampled for "Before They Were Famous" listener-count ranking

    // Displayed in the footer, and compared against the latest GitHub
    // release below to advise you when it's time to update
    'version' => '1.0.0',

    // Footer "update available" check: compares the version above against
    // the latest published release on this GitHub repo. Set github_repo to
    // '' to disable the check (and hide the GitHub link) entirely.
    'github_repo'      => 'MichelleFindlay/lastfm-dash',
    'update_check_ttl' => 3600,
];
