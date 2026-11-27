<?php

declare(strict_types=1);

// Minimal stand-in for the GitHub REST API, backed by local bare repositories.
// Run with: GD_FAKE_DIR=... GD_FAKE_REMOTES=... php -S 127.0.0.1:PORT fake-github.php
//   $GD_FAKE_REMOTES/<owner>/<repo>.git   bare repositories
//   $GD_FAKE_DIR/releases.json            [{tag_name, name, draft, prerelease}, …] oldest first
//   $GD_FAKE_DIR/requests.log             one line per request (status + If-None-Match)

$fake = (string) getenv('GD_FAKE_DIR');
$remotes = (string) getenv('GD_FAKE_REMOTES');
$uri = parse_url($_SERVER['REQUEST_URI']);
$path = $uri['path'] ?? '/';
parse_str($uri['query'] ?? '', $q);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

function respond(int $status, mixed $data): never
{
    global $fake, $headers;
    $body = json_encode($data, JSON_UNESCAPED_SLASHES);
    $etag = 'W/"' . md5($body) . '"';
    $inm = $headers['if-none-match'] ?? '-';
    if ($status === 200 && $inm === $etag) {
        $status = 304;
    }
    file_put_contents($fake . '/requests.log', $status . ' ' . $_SERVER['REQUEST_URI'] . ' inm=' . $inm . "\n", FILE_APPEND);
    http_response_code($status);
    if ($status === 304) {
        exit;
    }
    header('Content-Type: application/json');
    header('ETag: ' . $etag);
    echo $body;
    exit;
}

if (!str_starts_with($headers['authorization'] ?? '', 'Bearer test_token')) {
    respond(401, ['message' => 'Bad credentials']);
}
if ($path === '/rate_limit') {
    respond(200, ['resources' => []]);
}
if (preg_match('~^/repos/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+)(/.*)?$~', $path, $m) !== 1) {
    respond(404, ['message' => 'Not Found']);
}
$repo = "{$remotes}/{$m[1]}/{$m[2]}.git";
$rest = $m[3] ?? '';
if (!is_dir($repo)) {
    respond(404, ['message' => 'Not Found']);
}

$releases = json_decode((string) @file_get_contents($fake . '/releases.json'), true) ?: [];
$toApi = static fn (array $r): array => $r + [
    'name' => '',
    'draft' => false,
    'prerelease' => false,
    'html_url' => "https://github.com/{$m[1]}/{$m[2]}/releases/tag/" . $r['tag_name'],
    'published_at' => '2026-11-24T10:00:00Z',
    'author' => ['login' => 'releaser'],
];

switch ($rest) {
    case '':
        respond(200, ['full_name' => "{$m[1]}/{$m[2]}", 'permissions' => ['pull' => true]]);
    case '/commits':
        $branch = (string) ($q['sha'] ?? 'main');
        $n = max(1, (int) ($q['per_page'] ?? 30));
        exec(sprintf('git --git-dir=%s log -n %d --format=%%H%%x1f%%an%%x1f%%cI%%x1f%%s %s -- 2>/dev/null',
            escapeshellarg($repo), $n, escapeshellarg('refs/heads/' . $branch)), $lines, $code);
        if ($code !== 0 || $lines === []) {
            respond(404, ['message' => 'No commit found for SHA: ' . $branch]);
        }
        $out = [];
        foreach ($lines as $line) {
            [$sha, $author, $date, $subject] = explode("\x1f", $line, 4);
            $out[] = [
                'sha' => $sha,
                'html_url' => "https://github.com/{$m[1]}/{$m[2]}/commit/{$sha}",
                'commit' => ['message' => $subject, 'author' => ['name' => $author], 'committer' => ['date' => $date]],
            ];
        }
        respond(200, $out);
    case '/releases/latest':
        foreach (array_reverse($releases) as $r) {
            if (empty($r['draft']) && empty($r['prerelease'])) {
                respond(200, $toApi($r));
            }
        }
        respond(404, ['message' => 'Not Found']);
    case '/releases':
        respond(200, array_map($toApi, array_reverse($releases)));
}
respond(404, ['message' => 'Not Found']);
