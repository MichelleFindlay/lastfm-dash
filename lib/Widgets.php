<?php

/**
 * Fun, derived "insight" widgets built on top of the LastFm API client.
 * Last.fm has no audio-feature (tempo/energy/mood) or celebrity-comparison
 * data, so widgets that would need it are honest approximations built from
 * real scrobble data instead of fabricated numbers — each says so in its
 * output rather than pretending to be something it isn't.
 */
class Widgets
{
    private const MOOD_TAGS = [
        'melancholy' => ['sad', 'melancholy', 'melancholic', 'emo', 'acoustic', 'ballad', 'slowcore', 'ambient', 'shoegaze', 'lo-fi', 'singer-songwriter', 'sadcore'],
        'hype'       => ['party', 'dance', 'edm', 'trap', 'drum and bass', 'dnb', 'hardstyle', 'hardcore', 'energetic', 'upbeat', 'anthem', 'rave', 'jungle'],
        'chill'      => ['chill', 'chillwave', 'relax', 'dream pop', 'downtempo', 'jazz', 'lounge', 'mellow', 'smooth', 'soft rock'],
        'aggressive' => ['metal', 'metalcore', 'punk', 'screamo', 'post-hardcore', 'grindcore', 'thrash', 'nu metal', 'hardcore punk', 'deathcore'],
        'feelgood'   => ['pop', 'feel good', 'happy', 'indie pop', 'summer', 'funk', 'disco', 'soul', 'pop rock'],
    ];

    private const MOOD_LABELS = [
        'melancholy' => 'mostly melancholy',
        'hype'       => 'high chance of hype',
        'chill'      => 'calm and chill',
        'aggressive' => 'stormy and aggressive',
        'feelgood'   => 'bright and feel-good',
    ];

    private LastFm $lastfm;
    private array $config;

    public function __construct(LastFm $lastfm, array $config)
    {
        $this->lastfm = $lastfm;
        $this->config = $config;
    }

    /**
     * Unix timestamps for your entire scrobble history, used to derive
     * listening-time patterns. Paginates user.getRecentTracks until Last.fm
     * reports no pages left (each page is a single cheap call with no
     * per-item lookup, so unlike the artist-based widgets below, fetching
     * everything is actually practical here). scrobble_sample_pages is a
     * safety ceiling, not a target — it only kicks in for accounts with an
     * enormous history. Cached for a day since listening patterns don't
     * shift hour to hour.
     */
    private function getScrobbleTimestamps(): array
    {
        $maxPages = max(1, (int) ($this->config['scrobble_sample_pages'] ?? 200));
        $timestamps = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $data = $this->lastfm->call('user.getrecenttracks', ['limit' => 200, 'page' => $page], 86400);
            $tracks = $data['recenttracks']['track'] ?? [];

            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }

            if (empty($tracks)) {
                break;
            }

            foreach ($tracks as $t) {
                $uts = $t['date']['uts'] ?? null;
                if ($uts !== null) {
                    $timestamps[] = (int) $uts;
                }
            }

            $totalPages = (int) ($data['recenttracks']['@attr']['totalPages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
        }

        return $timestamps;
    }

    private function timezone(): DateTimeZone
    {
        return LastFm::resolveTimezone($this->config['timezone'] ?? '');
    }

    /**
     * A 24-hour radial breakdown of when you scrobble, with a label for
     * your dominant listening period.
     */
    public function listeningClock(): array
    {
        $timestamps = $this->getScrobbleTimestamps();
        $tz = $this->timezone();

        $hours = array_fill(0, 24, 0);
        foreach ($timestamps as $uts) {
            $dt = (new DateTime('@' . $uts))->setTimezone($tz);
            $hours[(int) $dt->format('G')]++;
        }

        $total = array_sum($hours);
        if ($total === 0) {
            return ['available' => false];
        }

        return [
            'available'   => true,
            'hours'       => $hours,
            'label'       => $this->clockLabel($hours),
            'sample_note' => 'Based on your last ' . number_format($total) . ' scrobbles',
        ];
    }

    private function clockLabel(array $hours): string
    {
        $periods = [
            'Night Owl'       => range(0, 4),
            'Dawn Chorus'     => range(5, 7),
            'Morning Person'  => range(8, 10),
            'Midday Cruiser'  => range(11, 13),
            'Afternoon Drift' => range(14, 16),
            'Evening Unwind'  => range(17, 20),
            'Late Night'      => range(21, 23),
        ];

        $best = '';
        $bestScore = -1;
        foreach ($periods as $name => $hourRange) {
            $score = 0;
            foreach ($hourRange as $h) {
                $score += $hours[$h];
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $name;
            }
        }

        return $best;
    }

    /**
     * Real data: how much you scrobble on each day of the week. Framed as
     * "energy" in the sense of listening activity/volume — Last.fm exposes
     * no audio tempo or energy data, so this doesn't pretend to be that.
     */
    public function energyCurve(): array
    {
        $timestamps = $this->getScrobbleTimestamps();
        $tz = $this->timezone();

        $days = array_fill(0, 7, 0); // 0 = Monday ... 6 = Sunday
        foreach ($timestamps as $uts) {
            $dt = (new DateTime('@' . $uts))->setTimezone($tz);
            $days[((int) $dt->format('N')) - 1]++;
        }

        $total = array_sum($days);
        if ($total === 0) {
            return ['available' => false];
        }

        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $peakIndex = array_search(max($days), $days, true);

        return [
            'available' => true,
            'days'      => $days,
            'labels'    => $labels,
            'peak_day'  => $labels[$peakIndex],
        ];
    }

    /**
     * Total lifetime listening time, estimated (Last.fm doesn't record real
     * track durations for most scrobbles) and converted into a few absurd
     * real-world comparisons.
     */
    public function distanceListened(): array
    {
        $info = $this->lastfm->getInfo();
        $playcount = (int) ($info['playcount'] ?? 0);

        if ($playcount === 0) {
            return ['available' => false];
        }

        $avgMinutes = (float) ($this->config['avg_track_minutes'] ?? 3.5);
        $totalMinutes = $playcount * $avgMinutes;

        // All the flight-based comparisons share one assumed speed (~900
        // km/h commercial cruise speed), including the two planetary ones —
        // "laps around the Earth" already implies it (40,075km / 2,670min).
        // The planetary ones are gated behind a minimum count so casual
        // listeners don't see a silly "0.001 trips to Mars"; they only
        // appear once a real dent's been made.
        $comparisons = [
            ['label' => 'London → Tokyo flights', 'unit_minutes' => 710],
            ['label' => 'marathons at an average pace', 'unit_minutes' => 255],
            ['label' => 'laps around the Earth by plane', 'unit_minutes' => 2670],
            ['label' => 'full 24-hour days', 'unit_minutes' => 1440],
            ['label' => 'trips to the Moon by plane', 'unit_minutes' => 25627, 'min_count' => 0.05],
            ['label' => 'trips to Mars at its closest approach, by plane', 'unit_minutes' => 3640000, 'min_count' => 0.05],
        ];

        $results = [];
        foreach ($comparisons as $c) {
            $count = round($totalMinutes / $c['unit_minutes'], isset($c['min_count']) ? 2 : 1);
            if (isset($c['min_count']) && $count < $c['min_count']) {
                continue;
            }
            $results[] = ['label' => $c['label'], 'count' => $count];
        }

        return [
            'available'   => true,
            'total_hours' => round($totalMinutes / 60),
            'total_days'  => round($totalMinutes / 1440, 1),
            'comparisons' => $results,
        ];
    }

    /**
     * Your top artists, billed as a festival poster with headliner/main
     * stage/tent stage tiers based on play rank.
     */
    public function festivalPoster(): array
    {
        $limit = max(6, (int) ($this->config['festival_artist_limit'] ?? 12));
        $top = $this->lastfm->call('user.gettopartists', [
            'period' => $this->config['top_period'] ?? 'overall',
            'limit'  => $limit,
        ]);
        $artists = $top['topartists']['artist'] ?? [];

        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        if (empty($artists)) {
            return ['available' => false];
        }

        $lineup = [];
        foreach ($artists as $i => $a) {
            $tier = $i < 3 ? 'headliner' : ($i < 7 ? 'main' : 'tent');
            $lineup[] = ['name' => $a['name'] ?? '', 'tier' => $tier];
        }

        return [
            'available' => true,
            'year'      => date('Y'),
            'lineup'    => $lineup,
        ];
    }

    /**
     * A mood breakdown derived from your top artists' community tags,
     * mapped through a curated keyword dictionary. Same honesty caveat as
     * the genre breakdown: it's a real signal from real tags, not a
     * clinical measurement of your emotional state.
     */
    public function moodWeather(): array
    {
        $limit = max(6, (int) ($this->config['genre_artist_limit'] ?? 20));
        $top = $this->lastfm->call('user.gettopartists', [
            'period' => $this->config['top_period'] ?? 'overall',
            'limit'  => $limit,
        ]);
        $artists = $top['topartists']['artist'] ?? [];

        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        $moodScores = array_fill_keys(array_keys(self::MOOD_TAGS), 0);

        foreach ($artists as $artist) {
            $name = $artist['name'] ?? '';
            $playcount = (int) ($artist['playcount'] ?? 0);
            if ($name === '' || $playcount === 0) {
                continue;
            }

            $tagsData = $this->lastfm->call('artist.gettoptags', ['artist' => $name], 604800);
            $tags = $tagsData['toptags']['tag'] ?? [];
            if (isset($tags['name'])) {
                $tags = [$tags];
            }

            foreach (array_slice($tags, 0, 5) as $tag) {
                $tagName = strtolower(trim($tag['name'] ?? ''));
                $weight = max(1, (int) ($tag['count'] ?? 0));

                foreach (self::MOOD_TAGS as $mood => $keywords) {
                    if (in_array($tagName, $keywords, true)) {
                        $moodScores[$mood] += $weight * $playcount;
                    }
                }
            }
        }

        $total = array_sum($moodScores);
        if ($total <= 0) {
            return ['available' => false];
        }

        arsort($moodScores);
        $forecast = [];
        foreach ($moodScores as $mood => $score) {
            if ($score <= 0) {
                continue;
            }
            $forecast[] = [
                'mood'  => $mood,
                'label' => self::MOOD_LABELS[$mood],
                'pct'   => round($score / $total * 100, 1),
            ];
        }

        return [
            'available' => !empty($forecast),
            'month'     => date('F'),
            'forecast'  => $forecast,
        ];
    }

    /**
     * Average BPM across a sample of your top tracks — Last.fm has no
     * tempo data, so this looks each track up on Deezer's free public API
     * (no key required), which does carry real BPM metadata for most
     * catalogued tracks. Tracks Deezer has no match/BPM for are skipped
     * rather than guessed at.
     */
    public function bpmPulse(): array
    {
        $limit = max(5, (int) ($this->config['bpm_track_limit'] ?? 15));
        $top = $this->lastfm->call('user.gettoptracks', [
            'period' => $this->config['top_period'] ?? 'overall',
            'limit'  => $limit,
        ]);
        $tracks = $top['toptracks']['track'] ?? [];

        if (isset($tracks['name'])) {
            $tracks = [$tracks];
        }

        $bpms = [];
        foreach ($tracks as $t) {
            $artist = $t['artist']['name'] ?? '';
            $name = $t['name'] ?? '';
            if ($artist === '' || $name === '') {
                continue;
            }

            $bpm = $this->lookupDeezerBpm($artist, $name);
            if ($bpm > 0) {
                $bpms[] = $bpm;
            }
        }

        if (empty($bpms)) {
            return ['available' => false];
        }

        $avg = array_sum($bpms) / count($bpms);

        return [
            'available'   => true,
            'avg_bpm'     => round($avg, 1),
            'sample_size' => count($bpms),
            'pulse_label' => $this->pulseLabel($avg),
        ];
    }

    private function pulseLabel(float $bpm): string
    {
        if ($bpm < 60) {
            return 'practically meditative — resting heart rate territory';
        }
        if ($bpm < 100) {
            return 'a calm, resting pulse';
        }
        if ($bpm < 140) {
            return 'like a brisk walk';
        }
        if ($bpm < 170) {
            return 'a solid jog';
        }

        return 'sprinting — maybe see a doctor';
    }

    /**
     * Deezer's `artist:"..."` advanced search field breaks for multi-word
     * artist names (even quoted), so this uses a plain-text search instead
     * and picks the result whose artist name actually matches. BPM also
     * isn't included in search results — only the full track-detail
     * endpoint has it — so this is a two-step lookup, both cached 30 days.
     */
    private function lookupDeezerBpm(string $artist, string $track): float
    {
        $searchUrl = 'https://api.deezer.com/search?limit=5&q=' . rawurlencode($artist . ' ' . $track);
        $searchResponse = $this->lastfm->externalGet($searchUrl, 2592000);
        if ($searchResponse === null) {
            return 0;
        }

        $results = json_decode($searchResponse, true)['data'] ?? [];
        if (empty($results)) {
            return 0;
        }

        $trackId = null;
        foreach ($results as $r) {
            if (strcasecmp($r['artist']['name'] ?? '', $artist) === 0) {
                $trackId = $r['id'];
                break;
            }
        }
        $trackId = $trackId ?? $results[0]['id'] ?? null;

        if (!$trackId) {
            return 0;
        }

        $detailResponse = $this->lastfm->externalGet('https://api.deezer.com/track/' . (int) $trackId, 2592000);
        if ($detailResponse === null) {
            return 0;
        }

        $detail = json_decode($detailResponse, true);

        return (float) ($detail['bpm'] ?? 0);
    }

    /**
     * Your top artists with their current global Last.fm listener counts.
     * Shared by beforeFamous() and obscurityIndex() so both draw from the
     * same one pass of artist.getInfo lookups (cached a week — listener
     * counts don't move fast enough to need fresher data).
     *
     * @return array<int, array{name: string, listeners: int, your_plays: int}>
     */
    private function getArtistListenerCounts(): array
    {
        $limit = max(10, (int) ($this->config['obscure_artist_sample'] ?? 25));
        $top = $this->lastfm->call('user.gettopartists', [
            'period' => $this->config['top_period'] ?? 'overall',
            'limit'  => $limit,
        ]);
        $artists = $top['topartists']['artist'] ?? [];

        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        $candidates = [];
        foreach ($artists as $a) {
            $name = $a['name'] ?? '';
            $yourPlays = (int) ($a['playcount'] ?? 0);
            if ($name === '') {
                continue;
            }

            $info = $this->lastfm->call('artist.getinfo', ['artist' => $name], 604800);
            $listeners = (int) ($info['artist']['stats']['listeners'] ?? 0);
            if ($listeners <= 0) {
                continue;
            }

            $candidates[] = ['name' => $name, 'listeners' => $listeners, 'your_plays' => $yourPlays];
        }

        return $candidates;
    }

    /**
     * Your top artists ranked by lowest current global Last.fm listener
     * count — a real signal of how under-the-radar your favourites are.
     * Last.fm has no history API to say WHEN you first heard an artist, so
     * this doesn't claim to — it's "how obscure are they right now", not
     * literally "found before famous".
     */
    public function beforeFamous(): array
    {
        $candidates = $this->getArtistListenerCounts();

        if (empty($candidates)) {
            return ['available' => false];
        }

        usort($candidates, fn($a, $b) => $a['listeners'] <=> $b['listeners']);

        return [
            'available' => true,
            'artists'   => array_slice($candidates, 0, 6),
        ];
    }

    /**
     * The average and median global listener count across your top
     * artists — a single real, data-backed number for "how mainstream is
     * your taste, really". Median is the headline figure since one outlier
     * superstar can badly skew the average.
     */
    public function obscurityIndex(): array
    {
        $candidates = $this->getArtistListenerCounts();

        if (empty($candidates)) {
            return ['available' => false];
        }

        $listeners = array_column($candidates, 'listeners');
        sort($listeners);

        $count = count($listeners);
        $mid = intdiv($count, 2);
        $median = $count % 2 === 0
            ? ($listeners[$mid - 1] + $listeners[$mid]) / 2
            : $listeners[$mid];

        return [
            'available'        => true,
            'avg_listeners'    => (int) round(array_sum($listeners) / $count),
            'median_listeners' => (int) round($median),
            'sample_size'      => $count,
            'label'            => $this->obscurityLabel($median),
        ];
    }

    private function obscurityLabel(float $medianListeners): string
    {
        if ($medianListeners < 10000) {
            return "deeply underground — you're basically a talent scout";
        }
        if ($medianListeners < 100000) {
            return 'niche — most people have no idea who these are';
        }
        if ($medianListeners < 1000000) {
            return 'mid-tier — known in the right circles';
        }
        if ($medianListeners < 10000000) {
            return 'popular — genuinely famous artists';
        }

        return 'mainstream — certified global superstars';
    }
}
