<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Domains;
use GitDeploy\Env;
use GitDeploy\Request;
use GitDeploy\Settings;
use GitDeploy\Site;
use GitDeploy\SiteForm;
use GitDeploy\Sites;
use GitDeploy\UserData;

final class SiteFormTest extends TestCase
{
    private UserData $ud;
    private Sites $sites;

    protected function setUp(): void
    {
        parent::setUp();
        $home = $this->tmp . '/home';
        mkdir($home . '/domains/example.com/public_html', 0755, true);
        mkdir($home . '/domains/other.org', 0755, true);
        $this->ud = new UserData('alice', $home, '/bin/bash');
        $this->ud->ensure();
        $this->sites = new Sites($this->ud);
    }

    private function submit(array $post, ?Site $existing = null): array
    {
        $post += [
            'hostname' => 'example.com',
            'environment' => 'testing',
            'repo' => 'acme/app',
            'branch' => 'main',
            'auto_deploy' => '1',
            'php' => 'default',
            'web_dir' => 'public',
            'shared_paths' => ".env\nstorage",
            'keep_releases' => '5',
            'base_path' => '',
            'script' => '',
        ];
        $env = Env::detect();
        return (new SiteForm($env, $this->ud, Settings::load($env), $this->sites))->fromRequest(Request::fake([], $post), $existing);
    }

    public function testDefaultsForDomainAndSubdomain(): void
    {
        [$site, $errors] = $this->submit([]);
        self::assertSame([], $errors);
        self::assertSame($this->ud->home . '/domains/example.com', $site->basePath);
        self::assertSame($this->ud->home . '/domains/example.com/current/public', $site->docrootPath());
        self::assertStringContainsString('$CREATE_RELEASE()', $site->script);

        [$sub, $errors] = $this->submit(['hostname' => 'test.example.com']);
        self::assertSame([], $errors);
        self::assertSame($this->ud->home . '/domains/example.com/apps/test', $sub->basePath);
    }

    public function testRejectsForeignHostsAndBadPaths(): void
    {
        self::assertArrayHasKey('hostname', $this->submit(['hostname' => 'someone-else.net'])[1]);
        self::assertArrayHasKey('base_path', $this->submit(['base_path' => '/etc/app'])[1]);
        self::assertArrayHasKey('base_path', $this->submit(['base_path' => '~/../bob/app'])[1]);
        self::assertArrayHasKey('base_path', $this->submit(['base_path' => '~/domains/example.com/public_html/app'])[1]);
        self::assertArrayHasKey('base_path', $this->submit(['base_path' => '~/.laravel-github-deploy/x'])[1]);
        self::assertArrayHasKey('web_dir', $this->submit(['web_dir' => '../secret'])[1]);
        self::assertArrayHasKey('shared_paths', $this->submit(['shared_paths' => "../../etc"])[1]);
        self::assertArrayHasKey('branch', $this->submit(['branch' => '--upload-pack=x'])[1]);
        self::assertArrayHasKey('repo', $this->submit(['repo' => 'not a repo'])[1]);
        self::assertArrayHasKey('script', $this->submit(['script' => 'echo no macros'])[1]);
        self::assertArrayHasKey('keep_releases', $this->submit(['keep_releases' => '0'])[1]);
    }

    public function testRejectsBasePathEscapingHomeThroughSymlink(): void
    {
        symlink('/tmp', $this->ud->home . '/escape');
        self::assertArrayHasKey('base_path', $this->submit(['base_path' => '~/escape/app'])[1]);
    }

    public function testRejectsOverlappingSites(): void
    {
        [$site] = $this->submit([]);
        $site->id = 'example-com';
        $this->sites->save($site);
        // Production on example.com + testing on test.example.com (default bases) is the common setup.
        [, $errors] = $this->submit(['hostname' => 'test.example.com']);
        self::assertSame([], $errors);
        [, $errors] = $this->submit(['hostname' => 'other.org']);
        self::assertSame([], $errors);
        // Same base, or a base inside another site's releases/shared/current, is refused.
        self::assertArrayHasKey('base_path', $this->submit(['hostname' => 'test.example.com', 'base_path' => '~/domains/example.com'])[1]);
        self::assertArrayHasKey('base_path', $this->submit(['hostname' => 'test.example.com', 'base_path' => '~/domains/example.com/shared/x'])[1]);
        self::assertArrayHasKey('base_path', $this->submit(['hostname' => 'test.example.com', 'base_path' => '~/domains/example.com/current'])[1]);
        // Editing the same site does not conflict with itself.
        [, $errors] = $this->submit([], $site);
        self::assertSame([], $errors);
    }

    public function testDomainHelpers(): void
    {
        $domains = ['example.com', 'shop.example.com'];
        self::assertSame('shop.example.com', Domains::owner('a.shop.example.com', $domains));
        self::assertNull(Domains::owner('example.org', $domains));
        self::assertSame('/h/domains/shop.example.com/apps/a', Domains::defaultBase('/h', 'a.shop.example.com', $domains));
    }
}
