# lastfm-dash

A single-page Last.fm dashboard: what you're playing right now, your favourite
and trending tracks, a genre breakdown, lifetime stats, and a handful of fun
data-driven insight widgets — all themed dynamically from the current track's
album art.

## Features

- **Now Playing** — live "now scrobbling" / last-played track, polled every
  few seconds, with separate track and album artwork tiles (they can differ).
- **Quick-listen links** — Spotify and YouTube Music links on the current
  track and on hover over any track row, so you don't have to leave the
  dashboard to find it yourself. Work as plain search links with zero setup;
  add free API credentials to upgrade to a verified direct link to the exact
  track (see `config.sample.php` and `lib/ListenLinks.php`).
- **Dynamic theming** — the page background and accent colour are extracted
  live from the current album art and eased in smoothly, with the accent
  colour clamped to a safe contrast range so text always stays readable
  regardless of the source image.
- **Favourite Tracks**, **Trending**, and **Genre Breakdown** — each with an
  All Time / This Year / This Month / Today period picker, switched via AJAX.
- **Lifetime Stats** — total scrobbles, unique artists/albums/tracks, average
  scrobbles per day, member-since date. Refreshes every 15 minutes.
- **Insight widgets** — click-through popups built from real Last.fm (and,
  where Last.fm has no data of its own, honestly-labelled derived) data:
  - **Listening Clock** — a 24-hour radial chart of when you actually listen
  - **Energy Curve** — scrobble activity across the week (not audio tempo —
    Last.fm doesn't expose that)
  - **Distance Listened** — your estimated total listening time, converted
    into flights, marathons, and (for heavy listeners) trips to the Moon or
    Mars
  - **If Your Year Were a Festival** — your top artists billed as a festival
    poster lineup
  - **Mood Weather** — a monthly emotional "forecast" derived from your top
    artists' community tags
  - **BPM Average** — your average tempo, sourced from Deezer's free API
    since Last.fm has no tempo data of its own
  - **Before They Were Famous** — your favourite artists with the lowest
    current global Last.fm listener counts
  - **Obscurity Index** — the average/median global listener count across
    your top artists
- **Self-update check** — the footer compares the installed version against
  the latest GitHub release and links to it when an update is available.

## Requirements

- PHP 7.4+ (8.x recommended)
- A Last.fm API key — get one free at
  [last.fm/api/account/create](https://www.last.fm/api/account/create)
- No database required — all caching is flat-file, under `cache/`

The `curl` extension is used when available, with an automatic
`allow_url_fopen` fallback if it isn't (see `lib/Http.php`).

## Setup

1. Copy the sample config and fill in your details:
   ```sh
   cp config.sample.php config.php
   ```
   Edit `config.php` and set at least `api_key` and `username`. Every other
   setting has a sensible default — see the comments in `config.sample.php`
   for what each one does (poll interval, cache TTLs, panel sizes, timezone,
   widget sampling limits, etc.). `config.php` is gitignored so your API key
   never gets committed.

2. Point a PHP-capable web server at the project root, or run PHP's
   built-in server for local testing:
   ```sh
   php -S localhost:8000
   ```
   Then open `http://localhost:8000`.

3. On shared/Apache hosting, `.htaccess` and `.user.ini` are included to
   toggle PHP error display between debug and production — see the comments
   in each file.

## Background cache warming (optional)

Widget and Lifetime Stats data is cached for 15 minutes. Left alone, that
just means whoever loads the page after the cache expires triggers the
refresh themselves — for most of the insight widgets that's fast (data is
served from long-lived sub-caches), but a handful (Genre Breakdown, BPM
Average, Obscurity Index, Before They Were Famous) sample deep enough into
your library that a fully cold run can take a minute or more.

`cron.php` pre-warms all of that ahead of time, so visitors always land on
an already-cached page. Schedule it to run every 15 minutes:

```sh
# Real system cron (preferred if you have shell access — no execution-time
# limit imposed by a web server or reverse proxy to worry about):
0,15,30,45 * * * * php /full/path/to/lastfm-dash/cron.php >/dev/null 2>&1
```

If you're on shared hosting without shell access, most control panels offer
a URL-based "cron job" feature instead:

```sh
0,15,30,45 * * * * curl -s "https://yourdomain.com/path/cron.php?token=YOUR_CRON_SECRET" >/dev/null
```

Note that a URL-triggered run is at the mercy of your web server's own
request timeout, which a very first (fully cold) run can exceed — real
system cron doesn't have that ceiling. Once you've scheduled either form,
set `'cron_enabled' => true` in `config.php` so the dashboard shows a small
footer note confirming background refresh is active. If you set
`'cron_secret'`, it's required as a `?token=` query param for HTTP-triggered
runs (CLI runs are always allowed) — worth setting if this URL is easily
guessable and you'd rather not have random hits trigger an expensive run.

## License

GPL-3.0 — see [LICENSE](LICENSE).
