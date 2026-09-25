<?php

/**
 * JSON endpoint for the insight widget popups. Each widget is computed
 * lazily on first click (rather than on every page load) since a couple of
 * them paginate through scrobble history, which is too slow to do on every
 * request. The full response is then cached for 15 minutes (see
 * WidgetCache::remember()) and rebuilt from scratch on the next request once
 * that expires — or pre-warmed ahead of time by cron.php, if you've set
 * that up (see cron_enabled in config.php).
 */

header('Content-Type: application/json');

// Some widgets sample deep into your library (each extra artist/track costs
// its own external API call, cached afterward but not on a cold miss), so
// the default 30s execution limit some hosts set isn't always enough.
// Silently no-ops on hosts where set_time_limit is disabled.
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

require __DIR__ . '/lib/LastFm.php';
require __DIR__ . '/lib/Widgets.php';
require __DIR__ . '/lib/WidgetCache.php';
require __DIR__ . '/lib/WidgetRegistry.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing config.php']);
    exit;
}

$config = require $configFile;
$id = $_GET['id'] ?? '';

$lastfm = new LastFm($config['api_key'], $config['username'], (int) ($config['cache_ttl'] ?? 60));
$widgets = new Widgets($lastfm, $config);
$handlers = WidgetRegistry::handlers($lastfm, $widgets, $config);

if (!isset($handlers[$id])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown widget']);
    exit;
}

$data = WidgetCache::remember($id, $_GET, 900, $handlers[$id]);

echo json_encode(['ok' => true, 'id' => $id, 'data' => $data]);
