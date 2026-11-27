<?php

declare(strict_types=1);

// Root: process one user (started in the background by dispatcher.php).

require dirname(__DIR__) . '/src/bootstrap.php';

use GitDeploy\Env;
use GitDeploy\Worker;

$user = (string) ($argv[1] ?? '');
try {
    exit((new Worker(Env::detect()))->run($user));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
