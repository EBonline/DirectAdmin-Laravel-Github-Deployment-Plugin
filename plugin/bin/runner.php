<?php

declare(strict_types=1);

// Runs AS THE USER (started by worker.php via runuser). Prints one JSON line.
// Manual use: php -c ../php.ini runner.php [--force-poll] [--site=<id>]

require dirname(__DIR__) . '/src/bootstrap.php';

use GitDeploy\Env;
use GitDeploy\Runner;
use GitDeploy\Settings;
use GitDeploy\UserData;

$opts = getopt('', ['force-poll', 'site:']);
$env = Env::detect();
try {
    $ud = UserData::current();
    if (posix_geteuid() === 0) {
        throw new RuntimeException('runner.php must not run as root');
    }
    @chdir($ud->home);
    $site = isset($opts['site']) && is_string($opts['site']) ? $opts['site'] : null;
    $result = (new Runner($env, $ud, Settings::load($env)))->run(isset($opts['force-poll']), $site);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
