<?php

require_once __DIR__ . '/Http.php';

/**
 * Looks up a track's UK (BPI) and US (RIAA) sales certifications — Silver,
 * Gold, Platinum, multi-Platinum, Diamond — from the certification table on
 * its English Wikipedia article, via the free MediaWiki API.
 *
 * Neither body has a public API: RIAA's database loads results through a
 * nonce-protected WordPress AJAX call, and BPI's search has no stable
 * endpoint at all. Wikipedia's song articles, though, carry those same
 * certifications as structured {{Certification Table Entry}} templates
 * (region, award, multiplier — each one cited back to BPI/RIAA), which is
 * what gets parsed here rather than any prose.
 *
 * Conservative in the same way as GrammyAwards: an article is only used if
 * its own song infobox names this track and this artist, and a table entry
 * crediting a different artist (a cover version's row on the same page) is
 * skipped. No match means no badge, never a guess.
 */
class Certifications
{
    private const CACHE_TTL = 2592000;
    private const API = 'https://en.wikipedia.org/w/api.php';

    // Units per award, single (track) certifications only — multi-Platinum
    // and multi-Diamond are further multiples of the base figure.
    private const UNITS = [
        'uk' => ['silver' => 200000, 'gold' => 400000, 'platinum' => 600000],
        'us' => ['gold' => 500000, 'platinum' => 1000000, 'diamond' => 10000000],
    ];

    private const REGIONS = [
        'uk' => ['wiki' => 'United Kingdom', 'label' => 'UK (BPI)'],
        'us' => ['wiki' => 'United States', 'label' => 'US (RIAA)'],
    ];

    private const TIER_RANK = ['silver' => 1, 'gold' => 2, 'platinum' => 3, 'diamond' => 4];

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
     * @return array{tier: string, multiplier: int, lines: string[], url: string}|null
     *     tier/multiplier of the single highest certification across both
     *     regions (drives the badge), plus one human-readable line per
     *     certified region for its tooltip
     */
    public function find(string $artist, string $track): ?array
    {
        if ($artist === '' || $track === '') {
            return null;
        }

        $cacheKey = 'certs_' . md5(strtolower($artist . '|' . $track));
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('found', $cached)) {
                return $cached['found'] ? $cached['data'] : null;
            }
        }

        $result = $this->lookup($artist, $track);
        if ($result === false) {
            // A request failed — an infrastructure hiccup, not a genuine
            // "not certified". Don't cache it.
            return null;
        }

        @file_put_contents($cacheFile, json_encode(['found' => $result !== null, 'data' => $result]));

        return $result;
    }

    /**
     * @return array|null|false false if a request failed
     */
    private function lookup(string $artist, string $track)
    {
        $search = $this->api([
            'action'   => 'query',
            'list'     => 'search',
            'srsearch' => $track . ' ' . $artist . ' song',
            'srlimit'  => 3,
        ]);
        if ($search === null || !isset($search['query']['search'])) {
            return false;
        }

        $titles = array_column($search['query']['search'], 'title');
        if (!$titles) {
            return null;
        }

        $pages = $this->api([
            'action'  => 'query',
            'prop'    => 'revisions',
            'rvprop'  => 'content',
            'rvslots' => 'main',
            'titles'  => implode('|', $titles),
        ]);
        if ($pages === null || !isset($pages['query']['pages'])) {
            return false;
        }

        // Keep search ranking order — the API returns pages unordered.
        $byTitle = [];
        foreach ($pages['query']['pages'] as $page) {
            $byTitle[$page['title'] ?? ''] = $page['revisions'][0]['slots']['main']['content'] ?? '';
        }

        foreach ($titles as $title) {
            $wikitext = $byTitle[$title] ?? '';
            if ($wikitext !== '' && self::isArticleFor($wikitext, $title, $artist, $track)) {
                return self::summarize(self::parseEntries($wikitext, $artist), $title);
            }
        }

        return null;
    }

    private function api(array $params): ?array
    {
        $params += ['format' => 'json', 'formatversion' => 2];
        $response = Http::get(self::API . '?' . http_build_query($params), [
            'User-Agent: ' . $this->userAgent,
        ]);

        $data = $response !== null ? json_decode($response, true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Whether one of the article's song infoboxes is for this track by
     * this artist.
     */
    private static function isArticleFor(string $wikitext, string $pageTitle, string $artist, string $track): bool
    {
        $trackNorm = self::normalize($track);
        $artistNorm = self::normalize($artist);
        $pageNorm = self::normalize(preg_replace('/\s*\([^)]*\)$/', '', $pageTitle));

        if (!preg_match_all('/\{\{\s*Infobox (song|single)(.*?)\n\}\}/is', $wikitext, $boxes)) {
            return false;
        }

        foreach ($boxes[2] as $box) {
            $name = preg_match('/\|\s*name\s*=\s*([^\n]*)/i', $box, $m) ? self::normalize(self::stripMarkup($m[1])) : '';
            if ($name !== $trackNorm && $pageNorm !== $trackNorm) {
                continue;
            }
            $credited = preg_match('/\|\s*artist\s*=\s*([^\n]*)/i', $box, $m) ? self::normalize(self::stripMarkup($m[1])) : '';
            if ($credited !== '' && strpos($credited, $artistNorm) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best single-certification entry per region.
     *
     * @return array<string, array{tier: string, multiplier: int, latin: bool}>
     */
    private static function parseEntries(string $wikitext, string $artist): array
    {
        $artistNorm = self::normalize($artist);
        $best = [];

        preg_match_all('/\{\{\s*Certification Table Entry\s*\|(.*?)\}\}/is', $wikitext, $entries);
        foreach ($entries[1] as $body) {
            $params = [];
            foreach (explode('|', $body) as $part) {
                if (strpos($part, '=') !== false) {
                    [$k, $v] = array_map('trim', explode('=', $part, 2));
                    $params[strtolower($k)] = $v;
                }
            }

            if (strtolower($params['type'] ?? 'single') !== 'single') {
                continue;
            }

            // A row crediting someone else is a cover version on this page.
            // BPI drops a leading "The" ("Killers"), so compare normalized,
            // either way round.
            if (!empty($params['artist'])) {
                $rowArtist = self::normalize($params['artist']);
                if (strpos($rowArtist, $artistNorm) === false && strpos($artistNorm, $rowArtist) === false) {
                    continue;
                }
            }

            $region = null;
            foreach (self::REGIONS as $key => $r) {
                if (strcasecmp($params['region'] ?? '', $r['wiki']) === 0) {
                    $region = $key;
                }
            }
            if ($region === null) {
                continue;
            }

            $award = strtolower($params['award'] ?? '');
            // Wikipedia marks RIAA Latin certifications with Spanish=yes.
            $latin = in_array($award, ['oro', 'platino', 'diamante'], true) || stripos($award, 'latin') !== false
                || in_array(strtolower($params['spanish'] ?? $params['latin'] ?? ''), ['yes', 'y', 'true', '1'], true);
            $tier = strtr(trim(str_ireplace(['(latin)', 'latin'], '', $award)), ['oro' => 'gold', 'platino' => 'platinum', 'diamante' => 'diamond']);
            if (!isset(self::TIER_RANK[$tier])) {
                continue;
            }
            $multiplier = max(1, (int) ($params['number'] ?? 1));

            $entry = ['tier' => $tier, 'multiplier' => $multiplier, 'latin' => $latin];
            if (!isset($best[$region]) || self::score($entry) > self::score($best[$region])) {
                $best[$region] = $entry;
            }
        }

        return $best;
    }

    private static function summarize(array $best, string $pageTitle): ?array
    {
        if (!$best) {
            return null;
        }

        $top = null;
        $lines = [];
        foreach (self::REGIONS as $region => $r) {
            if (!isset($best[$region])) {
                continue;
            }
            $e = $best[$region];
            if ($top === null || self::score($e) > self::score($top)) {
                $top = $e;
            }

            $line = $r['label'] . ': ' . ($e['multiplier'] > 1 ? $e['multiplier'] . '× ' : '') . ucfirst($e['tier'])
                . ($e['latin'] ? ' (Latin)' : '');
            // Latin titles have their own, lower thresholds — not listed here,
            // so no unit count rather than a wrong one.
            $base = self::UNITS[$region][$e['tier']] ?? null;
            if ($base !== null && !$e['latin']) {
                $line .= ' · ' . number_format($base * $e['multiplier']) . ' units';
            }
            $lines[] = $line;
        }

        return [
            'tier'       => $top['tier'],
            'multiplier' => $top['multiplier'],
            'lines'      => $lines,
            'url'        => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $pageTitle)),
        ];
    }

    /**
     * Higher is better. A standard certification always outranks a Latin
     * one — Latin thresholds are far lower, so "141× Platinum (Latin)"
     * represents fewer units than a standard 13× Platinum.
     */
    private static function score(array $entry): int
    {
        return ($entry['latin'] ? 0 : 1000000) + self::TIER_RANK[$entry['tier']] * 1000 + $entry['multiplier'];
    }

    private static function stripMarkup(string $s): string
    {
        $s = preg_replace('/<ref[^>]*\/>|<ref[^>]*>.*?<\/ref>/is', '', $s);
        $s = preg_replace('/\[\[(?:[^\]|]*\|)?([^\]]*)\]\]/', '$1', $s);
        $s = preg_replace('/\{\{[^}]*\}\}|<[^>]+>|\'\'+/', '', $s);

        return trim((string) $s);
    }

    /**
     * Lowercased, punctuation-free, without a leading "The" or
     * remaster/version suffixes — so "Mr Brightside" (BPI), "Mr. Brightside"
     * (Wikipedia) and "Mr. Brightside - Remastered" (Last.fm) all agree.
     */
    private static function normalize(string $s): string
    {
        $s = preg_replace([
            '/\s*[\(\[][^\)\]]*(remaster|version|edit|mono|stereo|mix)[^\)\]]*[\)\]]/i',
            '/\s+-\s+[^-]*(remaster|version|edit|mono|stereo|mix)[^-]*$/i',
        ], '', $s);
        $s = strtolower(str_replace('&', 'and', (string) $s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', trim((string) $s));

        return (string) preg_replace('/^the /', '', (string) $s);
    }
}
