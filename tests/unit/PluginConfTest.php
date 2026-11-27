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

    public function testVersionComesFromTheVersionFileBeforePluginConf(): void
    {
        $dir = $this->tmp;
        self::assertSame('unknown', Env::readVersion($dir));

        file_put_contents("$dir/plugin.conf", "id=x\nversion=1.2.3\nactive=yes\n");
        self::assertSame('1.2.3', Env::readVersion($dir), 'a checkout has no VERSION file');

        file_put_contents("$dir/VERSION", "1.2.4\n");
        self::assertSame('1.2.4', Env::readVersion($dir), 'the package VERSION wins');

        unlink("$dir/plugin.conf"); // as when DirectAdmin's rewritten plugin.conf is unreadable
        self::assertSame('1.2.4', Env::readVersion($dir));

        file_put_contents("$dir/VERSION", "<script>\n");
        self::assertSame('unknown', Env::readVersion($dir), 'only a version-shaped VERSION is used');
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
