<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Validate;

final class ValidateTest extends TestCase
{
    public function testRepoFormats(): void
    {
        self::assertSame('acme/app', Validate::repo('acme/app'));
        self::assertSame('acme/app', Validate::repo('https://github.com/acme/app'));
        self::assertSame('acme/app', Validate::repo('https://github.com/acme/app.git'));
        self::assertSame('acme/app.js', Validate::repo('git@github.com:acme/app.js.git'));
        self::assertSame('Acme-Co/my_app', Validate::repo(' Acme-Co/my_app/ '));
        self::assertNull(Validate::repo('acme'));
        self::assertNull(Validate::repo('acme/app/extra'));
        self::assertNull(Validate::repo('../etc/passwd'));
        self::assertNull(Validate::repo('acme/..'));
        self::assertNull(Validate::repo('https://gitlab.com/acme/app'));
    }

    public function testBranch(): void
    {
        foreach (['main', 'develop', 'feature/login', 'release-1.2', 'v2.x'] as $ok) {
            self::assertTrue(Validate::branch($ok), $ok);
        }
        foreach (['', '-rf', '/main', 'main/', 'a..b', 'a//b', 'x.lock', 'a b', 'a;rm', '$(id)'] as $bad) {
            self::assertFalse(Validate::branch($bad), $bad);
        }
    }

    public function testShaHostnameToken(): void
    {
        self::assertTrue(Validate::sha('a1b2c3d'));
        self::assertTrue(Validate::sha(str_repeat('f', 40)));
        self::assertFalse(Validate::sha('a1b2c3'));
        self::assertFalse(Validate::sha('G1b2c3d'));
        self::assertTrue(Validate::hostname('example.com'));
        self::assertTrue(Validate::hostname('test.my-site.example.co.uk'));
        self::assertFalse(Validate::hostname('Example.com'));
        self::assertFalse(Validate::hostname('-bad.com'));
        self::assertFalse(Validate::hostname('localhost'));
        self::assertTrue(Validate::token('github_pat_' . str_repeat('A1_', 20)));
        self::assertTrue(Validate::token('ghp_' . str_repeat('x', 36)));
        self::assertFalse(Validate::token('short'));
        self::assertFalse(Validate::token("ghp_abc\nX-Injected: 1" . str_repeat('x', 30)));
    }
}
