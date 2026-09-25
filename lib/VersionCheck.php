<?php

require_once __DIR__ . '/Http.php';

/**
 * Compares the locally checked-out git commit against the latest commit on
 * a GitHub branch, without shelling out to the git binary (reads .git
 * directly so it works even if shell_exec is disabled).
 */
class VersionCheck
{
    private string $repo;
    private string $branch;
    private string $gitDir;
    private string $cacheFile;
    private int $cacheTtl;

    public function __construct(string $repo, string $branch, string $rootDir, int $cacheTtl = 3600)
    {
        $this->repo = $repo;
        $this->branch = $branch;
        $this->gitDir = $rootDir . '/.git';
        $this->cacheTtl = $cacheTtl;
        $this->cacheFile = $rootDir . '/cache/version-check.json';
    }

    /**
     * @return array{local: ?string, remote: ?string, up_to_date: ?bool, compare_url: ?string, error: ?string}
     */
    public function check(): array
    {
        $result = [
            'local'       => null,
            'remote'      => null,
            'up_to_date'  => null,
            'compare_url' => null,
            'error'       => null,
        ];

        $local = $this->localCommit();
        if (!$local) {
            $result['error'] = 'not a git checkout';
            return $result;
        }
        $result['local'] = $local;

        $remote = $this->cachedRemoteCommit();
        if (!$remote) {
            $result['error'] = 'could not reach GitHub';
            return $result;
        }

        $remoteShort = substr($remote, 0, 7);
        $result['remote'] = $remoteShort;
        $result['up_to_date'] = strpos($remote, $local) === 0 || strpos($local, $remoteShort) === 0;
        $result['compare_url'] = "https://github.com/{$this->repo}/compare/{$local}...{$this->branch}";

        return $result;
    }

    private function localCommit(): ?string
    {
        $headFile = $this->gitDir . '/HEAD';
        if (!is_file($headFile)) {
            return null;
        }

        $head = trim((string) file_get_contents($headFile));

        if (strpos($head, 'ref:') !== 0) {
            // Detached HEAD: the file contains the commit sha directly.
            return substr($head, 0, 7);
        }

        $ref = trim(substr($head, 4));
        $refFile = $this->gitDir . '/' . $ref;

        if (is_file($refFile)) {
            return substr(trim((string) file_get_contents($refFile)), 0, 7);
        }

        $packedRefs = $this->gitDir . '/packed-refs';
        if (is_file($packedRefs)) {
            foreach (file($packedRefs) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                    continue;
                }
                [$sha, $refName] = array_pad(explode(' ', $line, 2), 2, null);
                if ($refName === $ref) {
                    return substr($sha, 0, 7);
                }
            }
        }

        return null;
    }

    private function cachedRemoteCommit(): ?string
    {
        if (is_file($this->cacheFile) && (time() - filemtime($this->cacheFile)) < $this->cacheTtl) {
            $cached = json_decode((string) file_get_contents($this->cacheFile), true);
            if (!empty($cached['sha'])) {
                return $cached['sha'];
            }
        }

        $url = "https://api.github.com/repos/{$this->repo}/commits/{$this->branch}";
        $response = Http::get($url, ['User-Agent: lastfm-dash', 'Accept: application/vnd.github+json'], 6);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        $sha = $data['sha'] ?? null;

        if ($sha) {
            $dir = dirname($this->cacheFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($this->cacheFile, json_encode(['sha' => $sha]));
        }

        return $sha;
    }
}
