<?php

declare(strict_types=1);

require __DIR__ . '/autoload.php';
require __DIR__ . '/helpers.php';

// Release files must stay readable for the web server; private files get explicit modes.
umask(0022);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Our php.ini replaces the server's, so pick up the system timezone ourselves.
(static function (): void {
    $link = @readlink('/etc/localtime');
    if (is_string($link) && preg_match('~zoneinfo/(.+)$~', $link, $m) === 1 && @date_default_timezone_set($m[1])) {
        return;
    }
    if (is_file('/etc/timezone')) {
        $name = trim((string) @file_get_contents('/etc/timezone'));
        if ($name !== '') {
            @date_default_timezone_set($name);
        }
    }
})();
