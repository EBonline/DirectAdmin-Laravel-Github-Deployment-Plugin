<?php

declare(strict_types=1);

// Called by the generated deploy script for $CREATE_RELEASE(), $ACTIVATE_RELEASE()
// and $RESTART_QUEUES(). Runs as the user, inside a deployment.

require dirname(__DIR__) . '/src/bootstrap.php';

use GitDeploy\Env;
use GitDeploy\Helper;
use GitDeploy\UserData;

if (posix_geteuid() === 0) {
    fwrite(STDOUT, "ERROR: helper.php must not run as root\n");
    exit(1);
}
exit((new Helper(Env::detect(), UserData::current()))->run($argv));
