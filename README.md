# lastfm-dash

A single-page Last.fm dashboard: what you're playing right now, your favourite
and trending tracks, a genre breakdown, lifetime stats, and a handful of fun
data-driven insight widgets — all themed dynamically from the current track's
album art.

## Features

- **Now Playing** — live "now scrobbling" / last-played track, polled every
  few seconds, with a "previously played" card alongside it showing the
  track before it.
- **ListenBrainz & Libre.fm import** (optional) — pulls your listening
  history from either or both into the local library, de-duplicated
  against Last.fm, and shows live "now scrobbling" from them on the Now
  Playing card too. See "Importing from ListenBrainz / Libre.fm" below.
- **Quick-listen links** — Spotify and YouTube Music links on the current
  track and on hover over any track row, so you don't have to leave the
  dashboard to find it yourself. Work as plain search links with zero setup;
  add free API credentials to upgrade to a verified direct link to the exact
  track (see `config.sample.php` and `lib/ListenLinks.php`). YouTube lookups
  are capped at 100 per rolling 24 hours (`youtube_daily_limit`) to stay
  clear of Google's free quota; excess lookups fall back to the search link.
  With a YouTube API key, a **Video** button also appears when the track has
  an official music video (no key, or no convincing match, and it stays
  hidden). That's a second YouTube search per new track, from the same
  daily cap.
- **Dynamic theming** — the page background and accent colour are extracted
  live from the current album art and eased in smoothly, with the accent
  colour clamped to a safe contrast range so text always stays readable
  regardless of the source image.
- **Favourite Tracks**, **Trending**, and **Genre Breakdown** — each with an
  All Time / This Year / This Month / This Week / Today period picker,
  switched via AJAX. Computed as exact calendar periods (real Jan 1, real
  1st-of-month, real Monday) from the local library snapshot once it's
  synced that far back — see "Local library sync" below. Once covered,
  Genre Breakdown scores every distinct artist scrobbled in that period
  (not a capped sample) with no "Other" catch-all; optionally filtered down
  to Spotify's own genre vocabulary if Spotify credentials are configured
  (see "Genre verification" below), and a "Show: all / above 1% / 2% / 5%"
  dropdown keeps a long genre list from making the page too tall.
- **Lifetime Stats** — total scrobbles, unique artists/albums/tracks, average
  scrobbles per day, member-since date. Refreshes every 15 minutes.
- **Insight widgets** — click-through popups built from real Last.fm (and,
  where Last.fm has no data of its own, honestly-labelled derived) data:
  - **Listening Clock** — a 24-hour radial chart of when you actually listen
  - **Energy Curve** — scrobble activity across the week (not audio tempo —
    Last.fm doesn't expose that)
  - **Distance Listened** — your estimated total listening time, converted
    into flights, marathons, and (for heavy listeners) trips to the Moon or
    Mars, each with a progress bar for how far into the current unit you
    are (e.g. "~74 flights, 64% of the way to your 75th")
  - **If Your Year Were a Festival** — your top artists billed as a festival
    poster lineup
  - **Mood Weather** — a monthly emotional "forecast" derived from your top
    artists' community tags
  - **BPM Average** — your average tempo, sourced from Deezer's free API
    since Last.fm has no tempo data of its own, shown as a scrolling
    ECG-style heart-monitor trace timed to the real beat interval
  - **Before They Were Famous** — your favourite artists with the lowest
    current global Last.fm listener counts, verified against Spotify's own
    artist search when configured so soundtrack/compilation scrobbles
    (where the "artist" is really an album or production title) don't show
    up as false "finds"
  - **Obscurity Index** — the average/median global listener count across
    your top artists (shares Before They Were Famous's filtering-out of
    multi-artist scrobble credits like "Artist A, Artist B & Artist C",
    which Last.fm otherwise treats as one low-listener "artist")
- **Self-update check** — the footer compares the installed version against
  the latest GitHub release and links to it when an update is available.
- **Security headers** — every page and endpoint sends HSTS, a nonce-based
  Content-Security-Policy, X-Frame-Options, X-Content-Type-Options,
  Referrer-Policy, Permissions-Policy and the Cross-Origin Opener/Resource/
  Embedder policies (A+ on securityheaders.com), and
  hides PHP's X-Powered-By. The footer shows a tick or cross for these and
  for DNSSEC on your domain, linking to securityheaders.com and Verisign's
  DNSSEC Analyzer to verify.
- **MCP server** (optional) — lets an AI client (Claude, ChatGPT/OpenAI, or
  anything else speaking MCP) query this account's data directly: full
  listening history with real dates/times, top artists/tracks/genres for
  any period, currently/previously playing, and every insight widget. See
  "MCP server" below.

## Requirements

- PHP 7.4+ (8.x recommended)
- An account on at least one of Last.fm, ListenBrainz or Libre.fm. For
  Last.fm you also need an API key — get one free at
  [last.fm/api/account/create](https://www.last.fm/api/account/create)
- No database required — all caching is flat-file, under `cache/`

The `curl` extension is used when available, with an automatic
`allow_url_fopen` fallback if it isn't (see `lib/Http.php`). The `zlib`
extension (bundled by default in almost every PHP build) is used to
compress the local library snapshot described below.

## Setup

1. Copy the sample config and fill in your details:
   ```sh
   cp config.sample.php config.php
   ```
   Edit `config.php` and set at least one of: `api_key` and `username`
   (Last.fm), `listenbrainz_username`, or `librefm_username`. Every other
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

## Local library sync (optional but recommended)

`cron.php` also grows a local, gzip-compressed copy of your full scrobble
history (artist + track + timestamp per scrobble) under `cache/`, so
Favourite Tracks, Trending, and Genre Breakdown can be computed straight
from that file for every period — with exact calendar boundaries instead
of Last.fm's approximate rolling windows, and with zero live API calls once
a given period is covered.

A large library can take a while to backfill in full: each cron run only
pulls a bounded batch (`library_backfill_pages_per_run` in `config.php`,
200 scrobbles per page, default 20 pages/run) rather than downloading
everything in one huge, rate-limit-risking request. New scrobbles since the
last run are always picked up cheaply on every run regardless of backfill
progress. Until a period's start date falls inside what's been backfilled,
that period transparently falls back to a live Last.fm call instead — nothing
breaks while backfill is still catching up, it's just not from the local
copy yet.

Genre Breakdown's local, uncapped view additionally needs Last.fm tags for
every distinct artist involved, which is its own paced background job —
`library_tag_backfill_per_run` (default 50/run), heaviest-played artists
first — so that too fills in gradually rather than needing a lookup burst
covering every artist you've ever scrobbled in one request.

This runs automatically as part of `cron.php` (see "Background cache
warming" above) — there's nothing extra to schedule.

## Importing from ListenBrainz / Libre.fm (optional)

If you also scrobble to [ListenBrainz](https://listenbrainz.org) or
[Libre.fm](https://libre.fm), or used to, set `listenbrainz_username`
and/or `librefm_username` in `config.php`. Each one you set does two
things:

- **Import:** `cron.php` pulls that service's full history into the local
  library snapshot, a bounded batch per run (`library_backfill_pages_per_run`
  pages: 1,000 listens per page for ListenBrainz, 200 for Libre.fm). It
  walks backward from when you enabled it, while new listens are picked up
  every run. Favourite Tracks, Trending, Genre Breakdown, the hover-tooltip
  insights and the MCP history tools then count those plays alongside your
  Last.fm ones.
- **Live listening:** the Now Playing card (and the MCP `get_now_playing`
  tool) also checks that service on every poll. If it's playing something
  and Last.fm isn't, that track is shown, labelled "via ListenBrainz" /
  "via Libre.fm". "Last played" and "Previously played" likewise use
  whichever service has the newest scrobble.

**History added later:** if you import older history into ListenBrainz or
Libre.fm after the dashboard has finished importing (say, a Spotify or
Last.fm export), `cron.php` notices within 6 hours (the service reports
more listens than it has seen) and walks that service's history again.
Listens it already has aren't added twice.

**Double-counting:** most people who use more than one service scrobble the
same plays to all of them. A play recorded by two services counts once:
same artist and track (ignoring case), timestamps within
`import_dedupe_seconds` (default 180) of each other. Matching is
one-to-one, so a track you really did play twice in a row still counts
twice. Two entries from the same service are never merged.

**Coverage:** a period counts as covered by the local library only once
Last.fm **and** every enabled import have backfilled that far back. So
enabling an import on an already-complete library briefly sends the longer
periods back to the live Last.fm fallback until the import catches up,
rather than showing totals that mix complete and partial histories. "Today"
and "This week" are usually covered again after the first run.

**Notes:**
- **No Last.fm account?** Leave Last.fm's `username` blank and the whole
  dashboard runs on ListenBrainz (or Libre.fm, if that's the only one set).
  Every place the app would ask Last.fm for your history, top tracks/artists
  or account stats is answered from your local imported history once it
  covers the request, and from that service's own API until then (see
  `lib/SourceUserApi.php`). That covers Now Playing, Favourite Tracks,
  Trending, Lifetime Stats, Listening Clock, Energy Curve, Distance,
  Festival, BPM and the MCP tools. ListenBrainz tracks get album art from
  the Cover Art Archive.
  - **Genres** (Genre Breakdown, Mood Weather) come from MusicBrainz artist
    tags. MusicBrainz allows about one request a second, so these are only
    looked up by `cron.php` (`library_tag_backfill_per_run` artists per run)
    and fill in over a few runs.
  - **Lifetime Stats:** your listen count and album total (from
    ListenBrainz's statistics, which it recalculates every so often) show
    straight away. Artist and track totals and "Member Since" (your first
    listen) come from your imported history and grow as the import
    progresses. On Libre.fm alone, Albums
    shows "—", since Libre.fm doesn't report an album count.
  - **Before They Were Famous / Obscurity Index** use ListenBrainz's
    listener counts (how many ListenBrainz users play each artist) instead
    of Last.fm's. They work for Libre.fm-only setups too, and fill in as
    cron tags your top artists, because the artist IDs come from the same
    MusicBrainz lookup as the genre tags. The numbers are smaller than
    Last.fm's, as ListenBrainz has fewer users, so the Obscurity Index
    scales them up (×20) before choosing its label.
  - **Track details** (artwork and album for Favourite Tracks/Trending, and
    the tooltip's global listeners, plays and duration) come from
    ListenBrainz's public popularity data for the artist, then Deezer for
    artwork, album and duration when ListenBrainz doesn't have the track.
    Global listener counts appear once cron has tagged the artist.
  - Adding just a Last.fm `api_key` (no username) switches genres, track
    details and listener counts to Last.fm's.
  - With both ListenBrainz and Libre.fm set, ListenBrainz answers the live
    queries, and both feed the local history.
- With a Last.fm account as well, album art, tags and track stats still
  come from Last.fm. **Lifetime Stats** include your imports as soon as
  they start arriving: Scrobbles is Last.fm's live total plus every
  imported play Last.fm doesn't have, and Artists, Tracks and Member Since
  are counted across every service. Albums stays Last.fm's count, since
  imported listens carry no album.
- **Loved tracks:** tracks you've loved on ListenBrainz or Libre.fm show the
  loved heart too, alongside Last.fm's. The lists refresh hourly.
- ListenBrainz listens are public by default, so a username is enough. Set
  `listenbrainz_token` (from your ListenBrainz settings page) if yours
  aren't. Libre.fm needs only a username; your Last.fm API key is never
  sent to either service.
- Disabling an import stops future imports but keeps listens already
  imported. To remove them completely, delete `cache/library_*.json.gz`
  and let `cron.php` rebuild it.

## Genre verification (optional)

With Spotify credentials configured (see "Quick-listen links" above),
Genre Breakdown filters Last.fm's community tags down to Spotify's own
genre vocabulary before scoring them, so tags that are really artist names,
list titles, or one-off scrobbler noise ("My Top Songs", "Upcoming Album
2023") don't show up as if they were genres. A few common alternate
spellings (Rnb/R&B, Dnb/Drum N Bass/Drum & Bass) are also collapsed onto
one canonical entry.

Spotify retired its live genre-list endpoint, so this vocabulary
(`SPOTIFY_GENRE_SEEDS` in `lib/LastFm.php`) is a fixed, hardcoded list
matching what Spotify used to expose — meaning it's honest but dated: a few
genuinely common modern genre names (e.g. "pop punk", "screamo") aren't on
it and get filtered out along with the real noise. If that's cutting too
much for your taste, add the genre names you want recognized directly to
that list.

Without Spotify credentials configured, this filtering is skipped entirely
and every Last.fm tag (past `GENRE_BLOCKLIST`) is used as before.

## MCP server (optional)

`mcp.php` exposes this account's data as tools an MCP-compatible AI client
can call — MCP (Model Context Protocol) is a shared, model-agnostic
standard, so the same endpoint works for Claude, ChatGPT/OpenAI, or any
other client speaking it. Read-only: every tool just reads existing data,
nothing here can modify anything.

**Setup:** set `mcp_api_key` in `config.php` to a long random secret (it's
blank by default, which disables the endpoint entirely — every request
404s until you set one; [generate one here](https://nexty.dev/tools/cron-secret-generator)
if you want a quick random value). Treat it like a password: don't paste
it into a chat, commit it, or share it — anyone with it can read your full
listening history through this endpoint.

**Tools available:**

| Tool | What it returns |
|---|---|
| `get_now_playing` | Currently/most recently played track, plus the one before it (including live listening from ListenBrainz/Libre.fm, with a `source` field) |
| `list_scrobbles` | Individual listening history — artist, track, exact date/time — with `since`/`until`/`limit` |
| `top_artists` / `top_tracks` | Ranked by play count for a period (`all_time`/`this_year`/`this_month`/`this_week`/`today`) |
| `genre_breakdown` | Genre percentages for a period, optionally Spotify-verified |
| `lifetime_stats` | Scrobble/artist/album/track totals, average per day, member since |
| `widget_*` | One tool per insight widget (`widget_listening_clock`, `widget_distance`, `widget_bpm`, `widget_before_famous`, etc.) |

`list_scrobbles`, `top_artists`, and `top_tracks` read from the local
library snapshot (see "Local library sync" above) when it covers the
requested range, honestly reporting `"available": false` with the current
backfill coverage if it doesn't yet — they never fall back to fabricated
or incomplete-looking data. `top_artists`/`top_tracks`/`genre_breakdown`
fall back to a live Last.fm call for the 5 named periods when local
history isn't there yet; `list_scrobbles` has no live equivalent for
arbitrary date ranges, so it just reports what's not covered.

Implements the request/response subset of MCP's Streamable HTTP transport
(`initialize`, `tools/list`, `tools/call` over a single POST, replying with
plain JSON) — not the optional SSE push stream, which a read-only tool
server like this one never needs to send anyway.

**Connecting a client** — every client needs the same two things: this
app's `mcp.php` URL, and your `mcp_api_key` sent as
`Authorization: Bearer <your mcp_api_key>` on every request.

### The Claude app (claude.ai web, Desktop, or mobile)

A connector is added to your account once via claude.ai on the web or the
Desktop app — it then shows up automatically everywhere you're signed in,
mobile included, with nothing extra to set up on each device.

1. Go to **Settings → Connectors → Add custom connector** (on Desktop:
   **Settings → Connectors**; the dialog is identical either way).
2. **Name:** anything you like, e.g. `lastfm`.
3. **URL:** your `mcp.php` address, e.g. `https://yourdomain.com/mcp.php`.
4. Under **Authentication**, choose **No sign-in** — pick this option even
   though Claude may show a yellow "sign-in detected" warning first; that
   warning just means the server correctly rejects unauthenticated
   requests, not that it needs OAuth. The warning's own text confirms
   this: *"If the server uses an API key instead of OAuth, add it under
   Request headers below."*
5. Under **Request headers**, click **Add header** and fill in:
   - **Header name:** `Authorization`
   - **Value:** `Bearer YOUR_MCP_API_KEY` — the literal word `Bearer`, a
     space, then your key. The key by itself will not authenticate.
   - Leave **Required** checked.
6. Click **Add** to save.

If you don't see a **Request headers** section in the dialog, your account
doesn't have that feature yet (it's newer and rolling out gradually) — use
Claude Code below instead in the meantime, since Desktop's older
OAuth-only connector flow has no way to send a custom API key at all.

### Claude Code

```sh
claude mcp add --transport http lastfm-dash https://yourdomain.com/mcp.php \
  --header "Authorization: Bearer YOUR_MCP_API_KEY"
```

Verify with `claude mcp list`.

### OpenAI (API)

The Responses API takes an `mcp`-type tool with a `headers` field for exactly
this kind of static-key auth — pass this in the `tools` array of your request:

```json
{
  "type": "mcp",
  "server_label": "lastfm",
  "server_url": "https://yourdomain.com/mcp.php",
  "headers": { "Authorization": "Bearer YOUR_MCP_API_KEY" },
  "require_approval": "never"
}
```

`require_approval: "never"` skips OpenAI's per-call confirmation prompt,
reasonable here since every tool is read-only. Headers are sent fresh with
every request rather than stored, so there's nothing to re-enter later if
you rotate the key — just update it in your own request code.

### ChatGPT app

ChatGPT's own custom-connector UI (**Settings → Connectors → Advanced →
Developer mode**, then **Create**) currently only offers **OAuth** or **No
authentication** for a custom MCP server — there's no field for a static
API key/Bearer header the way the API and Claude both support. Since
setting "No authentication" would mean anyone with the URL can read your
listening history, don't use that option here; the OpenAI API method
above is the working path for OpenAI until the ChatGPT app supports custom
headers. (Developer mode itself also needs a paid plan — Plus, Pro,
Business, Enterprise, or Edu; not available on Free.)

### Any other MCP client

The same URL, header name (`Authorization`), and value
(`Bearer YOUR_MCP_API_KEY`) work identically anywhere that lets you set a
custom request header — MCP's HTTP transport isn't tied to any one vendor.

## License

GPL-3.0 — see [LICENSE](LICENSE).
