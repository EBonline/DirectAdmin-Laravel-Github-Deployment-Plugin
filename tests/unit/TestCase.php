<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Fs;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gd-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->tmp = (string) realpath($this->tmp);
        putenv('GIT_DEPLOY_TEST_MODE=1');
        putenv('GIT_DEPLOY_PLUGIN_DATA=' . $this->tmp . '/plugin-data');
    }

    protected function tearDown(): void
    {
        Fs::remove($this->tmp);
        putenv('GIT_DEPLOY_TEST_MODE');
        putenv('GIT_DEPLOY_PLUGIN_DATA');
    }
}
