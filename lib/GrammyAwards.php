<?php

require_once __DIR__ . '/Http.php';

/**
 * Looks up whether a track has won a Grammy Award, via grammy.com's own
 * public artist search and each artist's per-track nominations table.
 *
 * There's no official Grammy API — the Recording Academy's only public
 * data partnership is a commercial one (with the credits database Jaxsta),
 * not an open API — and grammy.com itself isn't scraped blind here either:
 * its on-site artist search box calls a public, read-only Typesense search
 * endpoint whose API key ships in the page's own front-end JavaScript for
 * exactly this kind of direct use (the same request the site's own search
 * box makes), found by inspecting that JS rather than reverse-engineered
 * from anything private. Each artist's own grammy.com page then has a
 * complete, structured nominations table — every row explicitly marked
 * "winner-row" or not — which is what gets parsed here, not any sort of
 * fuzzy text search.
 *
 * Deliberately conservative: only an exact (case-insensitive) artist-name
 * match is accepted from the search — Typesense's typo-tolerant matching
 * happily returns a same-genre but different artist for a name it doesn't
 * recognize, which would be worse than reporting no match — and only an
 * exact (case-insensitive) track-title match within that artist's own
 * table. A near-miss is reported as "no award" rather than guessed at,
 * since confidently claiming a track won a Grammy when it didn't is a much
 * worse mistake than a wrong album-art thumbnail elsewhere in this app.
 */
class GrammyAwards
{
    private const SEARCH_HOST = 'ya4m695201l3bgepp-1.a2.typesense.net';
    private const SEARCH_API_KEY = 'cF9udROFiRE2xCH1YznjJLGfSQ08gCUx';

    private string $cacheDir;

    public function __construct(string $rootDir)
    {
        $this->cacheDir = $rootDir . '/cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * @return array{category: string, year: string, won: bool}|null
     */
    public function findAward(string $artist, string $track): ?array
    {
        if ($artist === '' || $track === '') {
            return null;
        }

        $nominations = $this->getNominations($artist);
        if (empty($nominations)) {
            return null;
        }

        $trackNorm = self::normalize($track);
        $best = null;
        foreach ($nominations as $nom) {
            if (self::normalize($nom['title']) !== $trackNorm) {
                continue;
            }
            // A track can be nominated more than once (different years or
            // categories) — prefer a win over a mere nomination if it has
            // both, since a win is the more notable, more certain claim.
            if ($best === null || ($nom['won'] && !$best['won'])) {
                $best = $nom;
            }
        }

        return $best;
    }

    private static function normalize(string $s): string
    {
        return strtolower(trim($s));
    }

    /**
     * @return array<int, array{category: string, year: string, won: bool, title: string}>
     */
    private function getNominations(string $artist): array
    {
        $cacheKey = 'grammy_noms_' . md5(strtolower($artist));
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $permalink = $this->findArtistPermalink($artist);
        if ($permalink === null) {
            return [];
        }

        $html = Http::get('https://www.grammy.com' . $permalink);
        if ($html === null) {
            // The request itself failed — an infrastructure hiccup, not a
            // genuine "no nominations". Don't cache it.
            return [];
        }

        $nominations = self::parseNominations($html);

        // Only reaching here means grammy.com gave a real, complete
        // answer — worth caching either way, including a genuine empty
        // table (an artist page that exists but has never been nominated).
        @file_put_contents($cacheFile, json_encode($nominations));

        return $nominations;
    }

    /**
     * @return array<int, array{category: string, year: string, won: bool, title: string}>
     */
    private static function parseNominations(string $html): array
    {
        $nominations = [];

        if (!preg_match('/<tbody id="artistNomsTableBody">(.*?)<\/tbody>/s', $html, $table)) {
            return $nominations;
        }

        preg_match_all('/<tr class="([^"]*)">(.*?)<\/tr>/s', $table[1], $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            $cell = $row[2];

            preg_match('/<td class="px-4" data-no-translation>([^<]+)<\/td>/', $cell, $title);
            if (!isset($title[1])) {
                continue;
            }

            preg_match('/>(\d{4})<\/a>/', $cell, $year);
            preg_match('/categories\/[^"]+"\s+class="text-body text-decoration-none">([^<]+)<\/a>/', $cell, $category);

            $nominations[] = [
                'year'     => $year[1] ?? '',
                'category' => trim($category[1] ?? ''),
                'title'    => trim($title[1]),
                'won'      => trim($row[1]) === 'winner-row',
            ];
        }

        return $nominations;
    }

    /**
     * This artist's grammy.com profile path (e.g. "/artists/miley-cyrus/18384/"),
     * or null if grammy.com doesn't have one under this name.
     */
    private function findArtistPermalink(string $artist): ?string
    {
        $cacheKey = 'grammy_artist_' . md5(strtolower($artist));
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('found', $cached)) {
                return $cached['found'] ? $cached['permalink'] : null;
            }
        }

        $permalink = $this->searchArtist($artist);

        // Last.fm sometimes credits a track to a comma-separated list of
        // artists ("Artist A, Artist B, & Artist C") rather than one named
        // act — if the full string isn't a real act on its own, retry with
        // just the first credited name.
        if ($permalink === null && strpos($artist, ',') !== false) {
            $first = trim(explode(',', $artist)[0]);
            if ($first !== '') {
                $permalink = $this->searchArtist($first);
            }
        }

        if ($permalink === false) {
            // The request itself failed — don't cache an infrastructure
            // hiccup as a permanent miss.
            return null;
        }

        @file_put_contents($cacheFile, json_encode(['found' => $permalink !== null, 'permalink' => $permalink]));

        return $permalink;
    }

    /**
     * @return string|null|false permalink on an exact match, null on a genuine
     *     "not on grammy.com", false if the request itself failed
     */
    private function searchArtist(string $artist)
    {
        $url = 'https://' . self::SEARCH_HOST . '/multi_search?x-typesense-api-key=' . self::SEARCH_API_KEY;
        $payload = [
            'searches' => [[
                'collection' => 'ra_artist',
                'q'          => $artist,
                'query_by'   => 'post_title,post_content',
                'per_page'   => 5,
            ]],
        ];

        $response = Http::postJson($url, $payload);
        if ($response === null) {
            return false;
        }

        $data = json_decode($response, true);
        $hits = $data['results'][0]['hits'] ?? [];

        foreach ($hits as $hit) {
            $title = $hit['document']['post_title'] ?? '';
            if (strcasecmp(trim($title), trim($artist)) === 0) {
                return $hit['document']['permalink'] ?? null;
            }
        }

        return null;
    }
}
