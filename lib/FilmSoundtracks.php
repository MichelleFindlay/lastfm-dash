<?php

require_once __DIR__ . '/Http.php';

/**
 * Looks up which films a track was used in, via MusicBrainz's free,
 * unauthenticated search API — specifically, which soundtrack release
 * groups the recording appears on (e.g. "The Breakfast Club: Original
 * Motion Picture Soundtrack" → "The Breakfast Club").
 *
 * There's no free, clean "songs used in films" API: Tunefind has the best
 * scene-by-scene data but only offers a partner API, and Wikidata barely
 * links songs to films at all. Soundtrack albums are the closest open
 * proxy, with two known gaps: a song used in a film but left off its
 * soundtrack album won't show up, and MusicBrainz's "Soundtrack" type also
 * covers TV series, games and themed compilations ("Classic Movies",
 * "The Essential Movies"). The obvious compilations are filtered out by
 * title below; the rest is accepted as noise rather than guessed at.
 *
 * MusicBrainz asks for at most one request per second per client and a
 * descriptive User-Agent, so requests are serialized through a lock file
 * (see throttle()) and every real answer — including "none" — is cached
 * for 30 days. Only looked up lazily on tooltip hover (see films.php).
 */
class FilmSoundtracks
{
    private const CACHE_TTL = 2592000;
    private const MIN_INTERVAL = 1.1;
    private const MAX_FILMS = 8;

    private string $cacheDir;
    private string $userAgent;

    public function __construct(string $rootDir, string $githubRepo = '')
    {
        $this->cacheDir = $rootDir . '/cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $version = is_file($rootDir . '/VERSION') ? trim((string) file_get_contents($rootDir . '/VERSION')) : 'dev';
        $this->userAgent = 'lastfm-dash/' . $version
            . ($githubRepo !== '' ? ' ( https://github.com/' . $githubRepo . ' )' : '');
    }

    /**
     * @return string[]|null film titles (possibly empty), or null if
     *     MusicBrainz couldn't be reached — not cached, so it's retried
     */
    public function findFilms(string $artist, string $track): ?array
    {
        if ($artist === '' || $track === '') {
            return [];
        }

        $cacheKey = 'films_' . md5(strtolower($artist . '|' . $track));
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $trackNorm = self::normalizeTitle($track);
        $query = 'recording:"' . self::escapePhrase($trackNorm) . '"'
            . ' AND artist:"' . self::escapePhrase($artist) . '"'
            . ' AND secondarytype:soundtrack';
        $url = 'https://musicbrainz.org/ws/2/recording?fmt=json&limit=100&query=' . rawurlencode($query);

        $response = $this->throttle(fn() => Http::get($url, [
            'User-Agent: ' . $this->userAgent,
            'Accept: application/json',
        ]));

        $data = $response !== null ? json_decode($response, true) : null;
        if (!is_array($data) || !isset($data['recordings'])) {
            // Request failed or was rate-limited (MusicBrainz answers a 503
            // with an error body, not a recordings list) — an infrastructure
            // hiccup, not a genuine "no films". Don't cache it.
            return null;
        }

        $films = [];
        foreach ($data['recordings'] as $recording) {
            if (self::normalizeTitle($recording['title'] ?? '') !== $trackNorm) {
                continue;
            }
            if (!self::creditMatches($recording['artist-credit'] ?? [], $artist)) {
                continue;
            }

            foreach ($recording['releases'] ?? [] as $release) {
                $film = self::filmFromReleaseGroup($release['release-group'] ?? [], $trackNorm);
                if ($film !== null) {
                    $films[strtolower($film)] = $film;
                }
            }
        }

        $films = array_slice(array_values($films), 0, self::MAX_FILMS);

        @file_put_contents($cacheFile, json_encode($films));

        return $films;
    }

    /**
     * The film's own title from a soundtrack release group, or null if it
     * isn't (or doesn't look like) a single film's soundtrack.
     */
    private static function filmFromReleaseGroup(array $group, string $trackNorm): ?string
    {
        $secondary = $group['secondary-types'] ?? [];
        if (!in_array('Soundtrack', $secondary, true)) {
            return null;
        }
        // The song's own single (often tagged Soundtrack when it was
        // released for a film), live recordings and DJ mixes aren't films.
        if (($group['primary-type'] ?? '') === 'Single'
            || array_intersect(['Live', 'DJ-mix', 'Remix', 'Interview'], $secondary)) {
            return null;
        }

        $title = trim((string) ($group['title'] ?? ''));
        if ($title === '' || self::normalizeTitle($title) === $trackNorm) {
            return null;
        }

        // Themed compilations and TV series volumes, not one film's soundtrack.
        // A numbered volume is only a giveaway without a "Film: ..." prefix —
        // "Guardians of the Galaxy: Awesome Mix, Vol. 1" is a real one.
        if (preg_match('/\b(movies|cinema|hits|essential|classics?|greatest|best of|collection|anthology|ytmnd|tv|series|season|episode|ceremony)\b/i', $title)
            || (strpos($title, ':') === false && preg_match('/\bvol(ume)?\.?\s*\d+/i', $title))) {
            return null;
        }

        $film = preg_replace([
            // "Film (Original Motion Picture Soundtrack)", "Film [Music From the Film]"
            '/\s*[\(\[][^\)\]]*(soundtrack|motion picture|music from|music inspired|\bost\b|score)[^\)\]]*[\)\]]\s*$/i',
            // "Film: Original Motion Picture Soundtrack", "Film - Music From the Motion Picture"
            // (a dash needs a space before it, so "Spider-Man" isn't split)
            '/(\s*:|\s+[\x{2013}\x{2014}-])\s*(original\s+)?(motion\s+picture\s+)?(music\s+(from|inspired by)\b.*|.*\bsoundtrack\b.*|ost|score)$/iu',
            // "Film Original Soundtrack", "Film OST"
            '/\s+(the\s+)?(original\s+)?(motion\s+picture\s+)?(soundtrack|ost)$/i',
        ], '', $title);
        $film = trim((string) $film);

        return $film !== '' ? $film : null;
    }

    private static function creditMatches(array $credits, string $artist): bool
    {
        $joined = '';
        foreach ($credits as $credit) {
            $name = (string) ($credit['name'] ?? ($credit['artist']['name'] ?? ''));
            if (strcasecmp(trim($name), trim($artist)) === 0) {
                return true;
            }
            $joined .= $name . ($credit['joinphrase'] ?? '');
        }

        return strcasecmp(trim($joined), trim($artist)) === 0;
    }

    /**
     * Lowercased, with curly quotes straightened and remaster/version
     * suffixes dropped — Last.fm often has "Song - Remastered 2011" where
     * MusicBrainz has the plain title.
     */
    private static function normalizeTitle(string $title): string
    {
        $title = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"], ["'", "'", '"', '"'], $title);
        $title = preg_replace([
            '/\s*[\(\[][^\)\]]*(remaster|version|edit|mono|stereo|mix)[^\)\]]*[\)\]]/i',
            '/\s+-\s+[^-]*(remaster|version|edit|mono|stereo|mix)[^-]*$/i',
        ], '', $title);

        return strtolower(trim((string) $title));
    }

    private static function escapePhrase(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }

    /**
     * Runs $request no sooner than MIN_INTERVAL after the previous
     * MusicBrainz request from any PHP process on this host, so several
     * tooltips hovered in quick succession don't get the whole host
     * rate-limited.
     */
    private function throttle(callable $request)
    {
        $lock = @fopen($this->cacheDir . '/musicbrainz.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return $request();
        }

        $last = (float) stream_get_contents($lock);
        $wait = $last + self::MIN_INTERVAL - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }

        try {
            return $request();
        } finally {
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string) microtime(true));
            fflush($lock);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
