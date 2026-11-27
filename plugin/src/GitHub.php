<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Minimal GitHub REST client. Conditional requests (If-None-Match) are used for polling:
 * a 304 response does not count against the token's rate limit.
 *
 * A "target" is what a site can deploy:
 *   ['id', 'type' => commit|release, 'ref', 'sha'|null, 'label', 'message', 'author', 'url', 'date', 'prerelease']
 */
final class GitHub
{
    public function __construct(private string $apiBase, private ?string $token)
    {
    }

    /** @return array{status:int, body:mixed, etag:?string} */
    public function get(string $path, ?string $etag = null): array
    {
        if ($this->token === null || $this->token === '') {
            throw new GitHubException('No GitHub token configured. Add one under Settings.');
        }
        if (!function_exists('curl_init')) {
            throw new GitHubException('The PHP curl extension is required.');
        }
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: laravel-github-deploy-directadmin-plugin',
            'Authorization: Bearer ' . $this->token,
        ];
        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        $respHeaders = [];
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new GitHubException('Could not reach GitHub: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = $body === '' ? null : json_decode((string) $body, true);
        if ($status >= 400 && $status !== 404) {
            throw new GitHubException($this->errorMessage($status, $data, $respHeaders), $status);
        }
        return ['status' => $status, 'body' => $data, 'etag' => $respHeaders['etag'] ?? null];
    }

    private function errorMessage(int $status, mixed $data, array $headers): string
    {
        $msg = is_array($data) && isset($data['message']) ? (string) $data['message'] : '';
        if ($status === 401) {
            return 'GitHub rejected the token (401). Check that it is valid and not expired.';
        }
        if ($status === 403 && ($headers['x-ratelimit-remaining'] ?? null) === '0') {
            $reset = (int) ($headers['x-ratelimit-reset'] ?? 0);
            return 'GitHub API rate limit reached' . ($reset ? ' until ' . date('H:i', $reset) : '') . '.';
        }
        if ($status === 403) {
            return 'The token has no access (403)' . ($msg !== '' ? ': ' . $msg : '.');
        }
        return "GitHub returned HTTP {$status}" . ($msg !== '' ? ': ' . $msg : '.');
    }

    /** @return array repository metadata; throws when it is missing or inaccessible. */
    public function repository(string $repo): array
    {
        $r = $this->get('/repos/' . $repo);
        if ($r['status'] === 404 || !is_array($r['body'])) {
            throw new GitHubException("Repository {$repo} was not found, or the token has no access to it.");
        }
        return $r['body'];
    }

    /** @return array{status:int, etag:?string, target:?array} */
    public function branchHead(string $repo, string $branch, ?string $etag = null): array
    {
        $r = $this->get('/repos/' . $repo . '/commits?per_page=1&sha=' . rawurlencode($branch), $etag);
        if ($r['status'] === 304) {
            return ['status' => 304, 'etag' => $etag, 'target' => null];
        }
        if ($r['status'] === 404 || !is_array($r['body']) || !isset($r['body'][0]['sha'])) {
            throw new GitHubException("Branch {$branch} was not found in {$repo}.");
        }
        return ['status' => 200, 'etag' => $r['etag'], 'target' => self::commitTarget($r['body'][0], $branch)];
    }

    /** Latest published, non-prerelease release. status 404 = no release yet. */
    public function latestRelease(string $repo, ?string $etag = null): array
    {
        $r = $this->get('/repos/' . $repo . '/releases/latest', $etag);
        if ($r['status'] === 304) {
            return ['status' => 304, 'etag' => $etag, 'target' => null];
        }
        if ($r['status'] === 404 || !is_array($r['body']) || !isset($r['body']['tag_name'])) {
            return ['status' => 404, 'etag' => null, 'target' => null];
        }
        return ['status' => 200, 'etag' => $r['etag'], 'target' => self::releaseTarget($r['body'])];
    }

    /** @return list<array> recent published releases (pre-releases included and flagged). */
    public function releases(string $repo, int $limit = 30): array
    {
        $r = $this->get('/repos/' . $repo . '/releases?per_page=' . $limit);
        $out = [];
        foreach (is_array($r['body']) ? $r['body'] : [] as $rel) {
            if (is_array($rel) && empty($rel['draft']) && isset($rel['tag_name'])) {
                $out[] = self::releaseTarget($rel);
            }
        }
        return $out;
    }

    /** @return list<array> recent commits on a branch. */
    public function commits(string $repo, string $branch, int $limit = 20): array
    {
        $r = $this->get('/repos/' . $repo . '/commits?per_page=' . $limit . '&sha=' . rawurlencode($branch));
        $out = [];
        foreach (is_array($r['body']) ? $r['body'] : [] as $c) {
            if (is_array($c) && isset($c['sha'])) {
                $out[] = self::commitTarget($c, $branch);
            }
        }
        return $out;
    }

    public static function commitTarget(array $c, string $branch): array
    {
        $sha = (string) $c['sha'];
        $message = (string) ($c['commit']['message'] ?? '');
        return [
            'id' => $sha,
            'type' => 'commit',
            'ref' => $branch,
            'sha' => $sha,
            'label' => substr($sha, 0, 7),
            'message' => strtok($message, "\n") ?: '',
            'author' => (string) ($c['commit']['author']['name'] ?? $c['author']['login'] ?? ''),
            'url' => isset($c['html_url']) ? (string) $c['html_url'] : null,
            'date' => self::time($c['commit']['committer']['date'] ?? null),
            'prerelease' => false,
        ];
    }

    public static function releaseTarget(array $r): array
    {
        $tag = (string) $r['tag_name'];
        return [
            'id' => $tag,
            'type' => 'release',
            'ref' => $tag,
            'sha' => null,
            'label' => $tag,
            'message' => (string) (($r['name'] ?? '') !== '' ? $r['name'] : $tag),
            'author' => (string) ($r['author']['login'] ?? ''),
            'url' => isset($r['html_url']) ? (string) $r['html_url'] : null,
            'date' => self::time($r['published_at'] ?? null),
            'prerelease' => (bool) ($r['prerelease'] ?? false),
        ];
    }

    private static function time(mixed $iso): ?int
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        $t = strtotime($iso);
        return $t === false ? null : $t;
    }
}
