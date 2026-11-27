<?php

declare(strict_types=1);

// Root cron entry point: /etc/cron.d/laravel_github_deploy runs this every minute.
// Use --foreground to process users synchronously (debugging).

require dirname(__DIR__) . '/src/bootstrap.php';

use GitDeploy\Dispatcher;
use GitDeploy\Env;

$opts = getopt('', ['foreground']);
exit((new Dispatcher(Env::detect()))->run(isset($opts['foreground'])));
