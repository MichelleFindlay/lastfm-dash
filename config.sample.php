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

    // Genre breakdown: how many top artists to sample tags from (each one
    // costs an extra Last.fm API call, cached a week — this is a real
    // ceiling on cost, not just a display limit, so raise it gradually if
    // your host can tolerate a slower first (cold-cache) load), and how
    // many genres to show before lumping the rest into "Other"
    'genre_artist_limit' => 200,
    'genre_limit'        => 8,

    // Insight widgets (Listening Clock, Energy Curve, Festival Poster, Mood
    // Weather, etc. — the clickable cards below Genre Breakdown)
    'avg_track_minutes'     => 3.5,   // used to estimate total listening time (Last.fm doesn't record real durations)
    'scrobble_sample_pages' => 200,   // safety ceiling on pages of 200 scrobbles fetched for Listening Clock/Energy Curve — pagination auto-stops once it reaches the end of your real history, so this only limits truly enormous accounts
    'festival_artist_limit' => 20,    // artists included in the festival poster lineup
    'timezone'              => '',    // IANA tz e.g. 'Europe/London' — leave blank to use the server's default
    'bpm_track_limit'       => 50,    // top tracks sampled for BPM lookup (2 Deezer calls each — Last.fm has no tempo data)
    'obscure_artist_sample' => 200,   // top artists sampled for "Before They Were Famous" / Obscurity Index (each costs an extra Last.fm call, cached a week)

    // How many pages of scrobble history (200/page) cron.php pulls per run
    // while building the local library snapshot (see "Local library sync"
    // in cron.php and lib/LibrarySync.php) — a large library backfills over
    // many runs rather than one huge one. Once a period is fully backfilled
    // locally, it's served from this file instead of a live Last.fm call.
    'library_backfill_pages_per_run' => 20,

    // Which timeframe each period-picker panel shows on page load. Visitors
    // can still switch it themselves — this only sets the initial view.
    // Valid values: all_time | this_year | this_month | this_week | today
    // (Computed as exact calendar periods — real Jan 1, real 1st-of-month,
    // real Monday — from the local library snapshot once it's backfilled
    // that far back; until then, falls back to Last.fm's own approximate
    // rolling-window data for that period. "today" always comes from your
    // actual same-day scrobbles, one way or the other.)
    'favourites_default_period' => 'all_time',
    'trending_default_period'   => 'today',
    'genre_default_period'      => 'all_time',

    // Widget and Lifetime Stats data is cached for 15 minutes. cron.php can
    // pre-warm that cache on a schedule so visitors never trigger a slow
    // cold computation themselves — see cron.php for setup instructions.
    // Set cron_enabled to true once you've actually scheduled it (shown as
    // a small footer note); cron_secret, if set, is required as a ?token=
    // query param for HTTP-triggered runs of cron.php (not for CLI runs).
    'cron_enabled' => false,
    'cron_secret'  => '',

    // Quick-listen links (Spotify / YouTube Music) on the current track and
    // on hover over any track row. Work out of the box as plain search
    // links with zero setup; adding credentials upgrades them to a
    // verified direct link to the exact track. See lib/ListenLinks.php for
    // where to get each one (both are free).
    'spotify_client_id'     => '',
    'spotify_client_secret' => '',
    'youtube_api_key'       => '',

    // Footer "update available" check: compares the app's own version (the
    // VERSION file at the project root — not this config) against the
    // latest published release on this GitHub repo. Set github_repo to ''
    // to disable the check (and hide the GitHub link) entirely.
    'github_repo'      => 'MichelleFindlay/lastfm-dash',
    'update_check_ttl' => 3600,
];
