<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Env;
use GitDeploy\Shell;

final class ShellTest extends TestCase
{
    private function boot(string $html): array
    {
        self::assertSame(1, preg_match('~<script type="application/json" id="gd-boot">(.*?)</script>~s', $html, $m));
        return json_decode($m[1], true);
    }

    public function testBootDataCannotCloseTheScriptTag(): void
    {
        $html = Shell::render(Env::detect(), '/CMD_PLUGINS/x', ['user' => '</script><script>alert(1)</script>', 'area' => 'user']);
        self::assertStringNotContainsString('</script><script>alert(1)', $html);
        $boot = $this->boot($html);
        self::assertSame('</script><script>alert(1)</script>', $boot['user']);
        self::assertIsInt($boot['server_time']);
    }

    public function testAssetsUseTheGivenBaseAndAContentHash(): void
    {
        $env = Env::detect();
        $html = Shell::render($env, '/CMD_PLUGINS/laravel_github_deploy-0.2', ['area' => 'user']);
        if (!is_file($env->pluginDir . '/images/app.js')) {
            self::assertStringContainsString('interface files', $html);
            self::markTestIncomplete('UI not built; run npm run build in ui/ to test asset URLs.');
        }
        self::assertMatchesRegularExpression('~src="/CMD_PLUGINS/laravel_github_deploy-0\.2/images/app\.js\?v=[0-9a-f]{12}"~', $html);
        self::assertStringContainsString('href="/CMD_PLUGINS/laravel_github_deploy-0.2/images/app.css?v=', $html);
        self::assertStringContainsString('<noscript>', $html);
    }
}
