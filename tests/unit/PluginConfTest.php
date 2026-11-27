<?php

declare(strict_types=1);

namespace GitDeploy\Tests;

use GitDeploy\Env;

/**
 * plugin.conf is what DirectAdmin's Plugin Manager reads to offer and install updates
 * (version_url, update_url). The release workflow publishes both files to the `dist` branch.
 */
final class PluginConfTest extends TestCase
{
    /** @return array<string,string> */
    private static function conf(): array
    {
        $conf = [];
        foreach (file(dirname(__DIR__, 2) . '/plugin/plugin.conf', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $conf[$key] = $value;
            }
        }
        return $conf;
    }

    public function testIdAndVersion(): void
    {
        $conf = self::conf();
        self::assertSame(Env::PLUGIN_ID, $conf['id'] ?? null);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $conf['version'] ?? '');
        self::assertSame($conf['version'], Env::detect()->version());
    }

    public function testUpdatesComeFromTheDistBranchWithoutRedirects(): void
    {
        $conf = self::conf();
        // DirectAdmin's plugin downloader does not follow redirects, so no release asset URLs.
        $base = 'https://raw.githubusercontent.com/EBonline/DirectAdmin-Laravel-Github-Deployment-Plugin/refs/heads/dist/';
        self::assertSame($base . 'version.txt', $conf['version_url'] ?? null);
        // Installing from this URL names the plugin folder (and its URLs) after the file.
        self::assertSame($base . Env::PLUGIN_ID . '.tar.gz', $conf['update_url'] ?? null);
    }
}
