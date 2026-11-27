<?php
// Usage: php json.php <file> <dot.path>   prints the value (JSON for arrays, "null" if missing)
// Usage: php json.php <file> <dot.path> --set <json>   sets the value
$data = json_decode((string) @file_get_contents($argv[1]), true) ?? [];
$keys = $argv[2] === '' ? [] : explode('.', $argv[2]);
if (($argv[3] ?? '') === '--set') {
    $ref = &$data;
    foreach ($keys as $k) {
        if (!is_array($ref)) { $ref = []; }
        $ref = &$ref[$k];
    }
    $ref = json_decode($argv[4], true);
    unset($ref);
    file_put_contents($argv[1], json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}
$v = $data;
foreach ($keys as $k) {
    if (!is_array($v) || !array_key_exists($k, $v)) { echo "null"; exit(0); }
    $v = $v[$k];
}
echo is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? 'null'));
