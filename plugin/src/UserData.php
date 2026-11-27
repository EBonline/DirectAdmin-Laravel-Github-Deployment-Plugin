<?php

declare(strict_types=1);

namespace GitDeploy;

/** Paths of a user's private plugin data in ~/.laravel-github-deploy (mode 0700). */
final class UserData
{
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';
    private const LOG_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public readonly string $dir;

    public function __construct(
        public readonly string $user,
        public readonly string $home,
        public readonly string $shell = '',
    ) {
        $this->dir = rtrim($home, '/') . '/.laravel-github-deploy';
    }

    /** The UNIX account this process runs as. */
    public static function current(): self
    {
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        if (!is_array($pw)) {
            throw new \RuntimeException('Cannot determine the current user (posix extension missing?).');
        }
        return new self((string) $pw['name'], self::testHome() ?? (string) $pw['dir'], (string) $pw['shell']);
    }

    /** Home directory override for the test suite (GIT_DEPLOY_TEST_MODE only). */
    public static function testHome(): ?string
    {
        $home = getenv('GIT_DEPLOY_HOME');
        return getenv('GIT_DEPLOY_TEST_MODE') === '1' && is_string($home) && $home !== '' ? $home : null;
    }

    public function ensure(): void
    {
        Fs::ensureDir($this->dir, 0700);
        @chmod($this->dir, 0700);
        foreach (['state', 'logs', 'queue', 'repos', 'locks', 'tmp'] as $sub) {
            Fs::ensureDir($this->dir . '/' . $sub, 0700);
        }
    }

    public function sitesFile(): string
    {
        return $this->dir . '/sites.json';
    }

    public function credentialsFile(): string
    {
        return $this->dir . '/credentials.json';
    }

    public function secretFile(): string
    {
        return $this->dir . '/.secret';
    }

    public function stateFile(string $site): string
    {
        return $this->dir . '/state/' . self::id($site) . '.json';
    }

    public function logDir(string $site): string
    {
        return $this->dir . '/logs/' . self::id($site);
    }

    public function logFile(string $site, string $logId): string
    {
        if (preg_match(self::LOG_ID_PATTERN, $logId) !== 1) {
            throw new \InvalidArgumentException('Invalid log id.');
        }
        return $this->logDir($site) . '/' . $logId . '.log';
    }

    public function queueFile(string $site): string
    {
        return $this->dir . '/queue/' . self::id($site) . '.json';
    }

    public function repoDir(string $site): string
    {
        return $this->dir . '/repos/' . self::id($site) . '.git';
    }

    public function lockFile(string $site): string
    {
        return $this->dir . '/locks/' . self::id($site) . '.lock';
    }

    public function tmpDir(): string
    {
        return $this->dir . '/tmp';
    }

    /** node/npm/npx links to the admin's Node.js override (Toolchain::shims()). */
    public function nodeBinDir(): string
    {
        return $this->dir . '/node-bin';
    }

    public function activationMarker(string $site, string $deployId): string
    {
        return $this->tmpDir() . '/' . self::id($site) . '-' . $deployId . '.activated';
    }

    public static function id(string $site): string
    {
        if (preg_match(self::ID_PATTERN, $site) !== 1) {
            throw new \InvalidArgumentException('Invalid site id.');
        }
        return $site;
    }
}
