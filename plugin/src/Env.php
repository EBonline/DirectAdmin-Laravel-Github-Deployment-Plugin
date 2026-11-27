<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Plugin-wide locations and environment. Test overrides (GIT_DEPLOY_*) are honoured
 * only when GIT_DEPLOY_TEST_MODE=1, so a user cannot redirect API or clone traffic.
 */
final class Env
{
    public const PLUGIN_ID = 'laravel_github_deploy';
    public const DEFAULT_PHP = '/usr/local/bin/php';
    public const DA_USERS_DIR = '/usr/local/directadmin/data/users';

    private function __construct(
        public readonly string $pluginDir,
        public readonly string $dataDir,
        public readonly bool $testMode,
    ) {
    }

    public static function detect(): self
    {
        $pluginDir = dirname(__DIR__);
        $test = getenv('GIT_DEPLOY_TEST_MODE') === '1';
        $dataDir = $pluginDir . '/data';
        $override = getenv('GIT_DEPLOY_PLUGIN_DATA');
        if ($test && is_string($override) && $override !== '') {
            $dataDir = rtrim($override, '/');
        }
        return new self($pluginDir, $dataDir, $test);
    }

    /**
     * DirectAdmin's URL name for the plugin: the folder it was installed in. Normally
     * equal to PLUGIN_ID, but the Plugin Manager may name the folder after the upload.
     */
    public function slug(): string
    {
        return basename($this->pluginDir);
    }

    public function testOverride(string $name): ?string
    {
        if (!$this->testMode) {
            return null;
        }
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function apiBase(): string
    {
        return rtrim($this->testOverride('GIT_DEPLOY_API_BASE') ?? 'https://api.github.com', '/');
    }

    /** URL the mirror is fetched from. */
    public function cloneUrl(string $repo): string
    {
        $base = $this->testOverride('GIT_DEPLOY_CLONE_BASE');
        return $base !== null
            ? rtrim($base, '/') . '/' . $repo . '.git'
            : 'https://github.com/' . $repo . '.git';
    }

    /** URL recorded as `origin` inside each release (never contains credentials). */
    public function publicRepoUrl(string $repo): string
    {
        return 'https://github.com/' . $repo . '.git';
    }

    /** PHP binary used to run the plugin's own scripts. */
    public function phpCli(): string
    {
        return $this->testOverride('GIT_DEPLOY_PHP_BIN') ?? self::DEFAULT_PHP;
    }

    public function phpIni(): string
    {
        return $this->pluginDir . '/php.ini';
    }

    public function daUsersDir(): string
    {
        return $this->testOverride('GIT_DEPLOY_DA_USERS_DIR') ?? self::DA_USERS_DIR;
    }

    /** Glob for CustomBuild PHP installs (/usr/local/php83 …). */
    public function phpDirGlob(): string
    {
        return $this->testOverride('GIT_DEPLOY_PHP_DIR_GLOB') ?? '/usr/local/php[0-9]*';
    }

    /** GIT_DEPLOY_* variables to pass on to child processes while testing. */
    public function testVars(): array
    {
        if (!$this->testMode) {
            return [];
        }
        $vars = [];
        foreach (getenv() as $k => $v) {
            if (str_starts_with((string) $k, 'GIT_DEPLOY_') && is_string($v)) {
                $vars[$k] = $v;
            }
        }
        return $vars;
    }

    public function version(): string
    {
        return self::readVersion($this->pluginDir);
    }

    /**
     * The package's VERSION file (written by build.sh), else version= in plugin.conf (a
     * checkout). DirectAdmin rewrites plugin.conf when it adds a plugin, as diradmin with
     * mode 0600, so the admin account that admin pages run as cannot read it.
     */
    public static function readVersion(string $pluginDir): string
    {
        $file = @file_get_contents($pluginDir . '/VERSION');
        if (is_string($file) && preg_match('/^[0-9A-Za-z.+-]+$/', trim($file)) === 1) {
            return trim($file);
        }
        $conf = @file_get_contents($pluginDir . '/plugin.conf');
        if (is_string($conf) && preg_match('/^version=(.+)$/m', $conf, $m) === 1) {
            return trim($m[1]);
        }
        return 'unknown';
    }
}
