<?php

declare(strict_types=1);

// Local stand-in for DirectAdmin's plugin URLs, for trying the interface in a browser.
// Started by serve.sh (php -S … router.php); never part of the plugin tarball.
//   /CMD_PLUGINS/<folder>/<page>.html|api.raw        → plugin/app/user.php
//   /CMD_PLUGINS_ADMIN/<folder>/<page>.html|api.raw  → plugin/app/admin.php
//   …/images/*                                        → plugin/images/*
// <folder> is the plugin folder's name, as DirectAdmin uses it: "plugin" in this checkout.
// Create <fixture>/logged-out to simulate an expired DirectAdmin session.

$plugin = (string) realpath(__DIR__ . '/../../plugin');
$slug = basename($plugin);
$uri = parse_url((string) $_SERVER['REQUEST_URI']);
$path = (string) ($uri['path'] ?? '/');
$query = (string) ($uri['query'] ?? '');

if ($path === '/' || $path === '') {
    header("Location: /CMD_PLUGINS/{$slug}/");
    return true;
}
if (preg_match('~^/CMD_PLUGINS(_ADMIN)?/' . preg_quote($slug, '~') . '(?:/(.*))?$~', $path, $m) !== 1) {
    http_response_code(404);
    echo 'Not a plugin URL';
    return true;
}
$area = ($m[1] ?? '') !== '' ? 'admin' : 'user';
$file = ($m[2] ?? '') !== '' ? $m[2] : 'index.html';

if (str_starts_with($file, 'images/')) {
    $asset = realpath($plugin . '/' . $file);
    if ($asset === false || !str_starts_with($asset, $plugin . '/images/')) {
        http_response_code(404);
        return true;
    }
    $types = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'map' => 'application/json'];
    header('Content-Type: ' . ($types[pathinfo($asset, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('Cache-Control: no-cache');
    readfile($asset);
    return true;
}

$fixture = (string) getenv('GD_DEV_FIXTURE');
if ($fixture !== '' && is_file($fixture . '/logged-out')) {
    // What DirectAdmin returns once the session has expired: its login page.
    echo '<!doctype html><html><body><form action="/CMD_LOGIN" method="post"><h1>DirectAdmin login</h1>'
        . '<p>Delete ' . htmlspecialchars($fixture) . '/logged-out to "log in" again.</p></form></body></html>';
    return true;
}

if (preg_match('~^([a-z]+)\.(html|raw)$~', $file, $f) !== 1 || !is_file("{$plugin}/{$area}/{$file}")) {
    http_response_code(404);
    echo 'Unknown plugin page';
    return true;
}

$method = (string) $_SERVER['REQUEST_METHOD'];
$env = getenv() + [];
$env['QUERY_STRING'] = $query;
$env['POST'] = $method === 'POST' ? (string) file_get_contents('php://input') : '';
$env['REQUEST_METHOD'] = $method;
$env['USERNAME'] = $area === 'user' ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '') : 'admin';
usleep((int) (getenv('GD_DEV_LATENCY_MS') ?: 0) * 1000);

$proc = proc_open([PHP_BINARY, '-c', "{$plugin}/php.ini", "{$plugin}/app/{$area}.php", $f[1]], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
$out = (string) stream_get_contents($pipes[1]);
$err = (string) stream_get_contents($pipes[2]);
proc_close($proc);
if ($err !== '') {
    error_log($err);
}

if ($f[2] === 'raw') {
    [$head, $body] = array_pad(explode("\n\n", $out, 2), 2, '');
    $lines = explode("\n", $head);
    if (preg_match('~^HTTP/1\.1 (\d{3})~', (string) array_shift($lines), $s) === 1) {
        http_response_code((int) $s[1]);
    }
    foreach ($lines as $line) {
        header($line);
    }
    echo $body;
    return true;
}

header('Content-Type: text/html; charset=utf-8');

// Like Evolution (DirectAdmin 1.681+), the plugin page is shown in a frame under a small header.
// The frame's own page is deliberately plain white, the worst case for dark mode.
if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe') {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>body{margin:0;background:#fff}</style></head><body>' . $out . '</body></html>';
    return true;
}

// The outer page stands in for Evolution: it sets html.mode:light / html.mode:dark from a
// Light/Dark/Auto switch, like the skin's own setting, so the plugin can be checked in both.
$self = htmlspecialchars((string) $_SERVER['REQUEST_URI']);
echo '<!doctype html><html lang="en" class="mode:light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<link rel="icon" href="data:,"><title>DirectAdmin (local)</title><style>'
    . 'html,body{height:100%;margin:0}body{display:flex;flex-direction:column;background:#eef0f3;color:#1f2933;font-family:system-ui,sans-serif}'
    . 'html.mode\:dark{color-scheme:dark}html.mode\:dark body{background:#0f1115;color:#e6e9ee}'
    . '.da-bar{background:#1f2a44;color:#fff;padding:10px 16px;font-size:13px;display:flex;gap:16px;align-items:center}.da-bar a{color:#cbd5e1}'
    . '.da-bar label{margin-left:auto}iframe{flex:1;width:100%;border:0;display:block}</style></head><body>'
    . '<div class="da-bar"><strong>DirectAdmin (local dev)</strong><a href="/CMD_PLUGINS/' . $slug . '/">User</a>'
    . '<a href="/CMD_PLUGINS_ADMIN/' . $slug . '/">Admin</a>'
    . '<label>Evolution mode <select id="da-mode"><option value="light">Light</option><option value="dark">Dark</option><option value="auto">Auto</option></select></label></div>'
    . '<iframe src="' . $self . '" title="Plugin"></iframe>'
    . '<script>(function(){var s=document.getElementById("da-mode"),q=matchMedia("(prefers-color-scheme: dark)"),h=document.documentElement;'
    . 'function set(){var d=s.value==="dark"||(s.value==="auto"&&q.matches);h.classList.toggle("mode:dark",d);h.classList.toggle("mode:light",!d);'
    . 'try{localStorage.setItem("da-mode",s.value)}catch(e){}}'
    . 'try{s.value=localStorage.getItem("da-mode")||"light"}catch(e){}s.onchange=set;q.addEventListener("change",set);set()})();</script>'
    . '</body></html>';
return true;
