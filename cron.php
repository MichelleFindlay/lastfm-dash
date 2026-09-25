<?php

/**
 * Pre-warms the insight-widget and lifetime-stats caches on a schedule, so
 * visitors always get an already-cached response instead of triggering a
 * slow cold computation (some widgets sample deep into your library and can
 * take a minute or more on a cache miss — see lib/Widgets.php) on their own
 * page load.
 *
 * Run this every 15 minutes, matching the cache TTL used in widgets.php and
 * LastFm::getInfo(). Two ways to schedule it (crontab syntax: minute 0,15,
 * 30,45 of every hour — equivalent to the more common "star-slash-15" form,
 * written out here so it doesn't get parsed as the end of this comment):
 *
 *   Real system cron (preferred, if you have shell access):
 *     0,15,30,45 * * * * php /full/path/to/lastfm-dash/cron.php >/dev/null 2>&1
 *
 *   URL-based "cron" (common on shared hosting control panels):
 *     0,15,30,45 * * * * curl -s "https://yourdomain.com/path/cron.php?token=YOUR_CRON_SECRET" >/dev/null
 *
 * Once scheduled, set 'cron_enabled' => true in config.php so the app knows
 * background refresh is in place (shown as a small footer note).
 *
 * If you set 'cron_secret' in config.php, HTTP requests must include a
 * matching ?token= to run this — leaving it blank allows any HTTP request
 * to trigger it, which is fine for most setups but worth locking down if
 * this URL is easily guessable and you'd rather not have random hits
 * trigger a potentially expensive run.
 */

if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

require __DIR__ . '/lib/LastFm.php';
require __DIR__ . '/lib/Widgets.php';
require __DIR__ . '/lib/WidgetCache.php';
require __DIR__ . '/lib/WidgetRegistry.php';

$isCli = PHP_SAPI === 'cli';

function respond(string $message, int $httpStatus = 200): void
{
    global $isCli;

    if ($isCli) {
        fwrite(STDOUT, $message . "\n");
        return;
    }

    http_response_code($httpStatus);
    header('Content-Type: text/plain');
    echo $message . "\n";
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    respond('cron.php: missing config.php', 500);
    exit(1);
}

$config = require $configFile;

if (!$isCli) {
    $secret = $config['cron_secret'] ?? '';
    if ($secret !== '' && ($_GET['token'] ?? '') !== $secret) {
        respond('cron.php: invalid or missing token', 403);
        exit(1);
    }
}

$lastfm = new LastFm($config['api_key'], $config['username'], (int) ($config['cache_ttl'] ?? 60));
$widgets = new Widgets($lastfm, $config);
$handlers = WidgetRegistry::handlers($lastfm, $widgets, $config);

$refreshed = [];
$failed = [];

foreach (WidgetRegistry::SIMPLE_IDS as $id) {
    try {
        WidgetCache::remember($id, ['id' => $id], 900, $handlers[$id]);
        $refreshed[] = $id;
    } catch (Throwable $e) {
        $failed[] = $id;
    }
}

// Lifetime Stats — shown on the main page and used by the Distance widget.
try {
    $lastfm->getInfo();
    $refreshed[] = 'lifetime_stats';
} catch (Throwable $e) {
    $failed[] = 'lifetime_stats';
}

$summary = sprintf(
    '[%s] lastfm-dash cron: refreshed %d/%d (%s)',
    date('c'),
    count($refreshed),
    count($refreshed) + count($failed),
    $failed ? 'failed: ' . implode(', ', $failed) : 'all ok'
);

respond($summary, $failed ? 500 : 200);
