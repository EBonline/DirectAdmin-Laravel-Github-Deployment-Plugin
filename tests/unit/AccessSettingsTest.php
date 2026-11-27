<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Access;
use GitDeploy\Env;
use GitDeploy\Settings;

final class AccessSettingsTest extends TestCase
{
    public function testPolicyAndOverrides(): void
    {
        $env = Env::detect();
        $settings = Settings::load($env);
        self::assertSame('ssh', $settings->get('access_policy'));
        self::assertTrue(Access::isAllowed($env, $settings, 'alice', '/bin/bash'));
        self::assertFalse(Access::isAllowed($env, $settings, 'bob', '/bin/false'));

        Access::setOverride($env, 'bob', 'allow');
        Access::setOverride($env, 'alice', 'deny');
        self::assertTrue(Access::isAllowed($env, $settings, 'bob', '/bin/false'));
        self::assertFalse(Access::isAllowed($env, $settings, 'alice', '/bin/bash'));
        self::assertSame(['alice' => 'deny', 'bob' => 'allow'], Access::overrides($env));
        Access::setOverride($env, 'alice', null);
        self::assertTrue(Access::isAllowed($env, $settings, 'alice', '/bin/bash'));

        Settings::save($env, ['access_policy' => 'allowlist'] + Settings::DEFAULTS);
        $settings = Settings::load($env);
        self::assertFalse(Access::isAllowed($env, $settings, 'alice', '/bin/bash'));
        self::assertTrue(Access::isAllowed($env, $settings, 'bob', '/bin/false'));
    }

    public function testInvalidUsernamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Access::setOverride(Env::detect(), '../etc', 'allow');
    }

    public function testSettingsValidation(): void
    {
        [$values, $errors] = Settings::validate(['poll_interval' => '0', 'deploy_timeout' => 'abc', 'access_policy' => 'root']);
        self::assertArrayHasKey('poll_interval', $errors);
        self::assertArrayHasKey('deploy_timeout', $errors);
        self::assertArrayHasKey('access_policy', $errors);
        self::assertSame(5, $values['poll_interval']);
        [$values, $errors] = Settings::validate(['poll_interval' => '2', 'keep_releases' => '3', 'reload_fpm' => false] + Settings::DEFAULTS);
        self::assertSame([], $errors);
        self::assertSame(2, $values['poll_interval']);
        self::assertFalse($values['reload_fpm']);
        self::assertSame('', $values['node_path']);
    }

    public function testNodePathsMustBeAbsolute(): void
    {
        [$values, $errors] = Settings::validate(['node_path' => 'bin/node', 'npm_path' => '/opt/../usr/bin/npm'] + Settings::DEFAULTS);
        self::assertArrayHasKey('node_path', $errors);
        self::assertArrayHasKey('npm_path', $errors);
        self::assertSame('', $values['node_path'], 'an invalid path falls back to PATH');
        [$values, $errors] = Settings::validate(['node_path' => ' /opt/alt/alt-nodejs22/root/usr/bin/node ', 'npm_path' => ''] + Settings::DEFAULTS);
        self::assertSame([], $errors);
        self::assertSame('/opt/alt/alt-nodejs22/root/usr/bin/node', $values['node_path']);
    }
}
