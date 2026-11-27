<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Root process for one user: runs bin/runner.php AS THAT USER (runuser), reads the
 * JSON summary from its stdout, gracefully reloads PHP-FPM for activated sites, and
 * stores a status file for the admin overview.
 */
final class Worker
{
    private const MAX_RUNTIME_MINUTES = 240;

    public function __construct(private Env $env)
    {
    }

    public static function lockFile(Env $env, string $user): string
    {
        return $env->dataDir . '/run/user-' . $user . '.lock';
    }

    public static function isRunning(Env $env, string $user): bool
    {
        $fh = @fopen(self::lockFile($env, $user), 'c');
        if ($fh === false) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    public function run(string $user): int
    {
        if (preg_match(Access::USER_PATTERN, $user) !== 1) {
            return 1;
        }
        if (!$this->env->testMode && posix_geteuid() !== 0) {
            fwrite(STDERR, "worker.php must run as root\n");
            return 1;
        }
        Fs::ensureDir($this->env->dataDir . '/run', 0700);
        $lock = @fopen(self::lockFile($this->env, $user), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        try {
            $pw = posix_getpwnam($user);
            $settings = Settings::load($this->env);
            if (!is_array($pw) || (int) $pw['uid'] === 0
                || !Access::isAllowed($this->env, $settings, $user, (string) $pw['shell'])) {
                return 0;
            }

            [$code, $stdout, $stderr] = Proc::capture($this->command($pw), '/', null);
            $result = $this->parseResult($stdout);
            $status = [
                'user' => $user,
                'updated_at' => time(),
                'sites' => self::sanitizeSites($result['sites'] ?? []),
                'nvm' => self::sanitizeNvm($result['nvm'] ?? null),
                'error' => null,
                'fpm' => [],
            ];
            if ($result === null) {
                $status['error'] = 'Runner failed (exit ' . $code . '): ' . substr(trim($stderr . "\n" . $stdout), 0, 2000);
            } elseif ($settings->get('reload_fpm')) {
                $status['fpm'] = $this->reloadFpm($result['activated'] ?? []);
            }
            self::writeStatus($this->env, $user, $status);
            return 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> */
    private function command(array $pw): array
    {
        $php = $this->env->phpCli();
        $runner = [$php, '-c', $this->env->phpIni(), $this->env->pluginDir . '/bin/runner.php'];
        $timeout = ['timeout', '--kill-after=60', self::MAX_RUNTIME_MINUTES . 'm'];

        if ($this->env->testMode && (int) $pw['uid'] === posix_geteuid()) {
            return array_merge($timeout, $runner);
        }
        $runuser = Proc::which(['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser']);
        if ($runuser === null) {
            throw new \RuntimeException('runuser not found');
        }
        $env = [
            '/usr/bin/env', '-i',
            'HOME=' . $pw['dir'],
            'USER=' . $pw['name'],
            'LOGNAME=' . $pw['name'],
            'PATH=/usr/local/bin:/usr/bin:/bin',
            'LANG=C.UTF-8',
        ];
        return array_merge($timeout, [$runuser, '-u', (string) $pw['name'], '--'], $env, $runner);
    }

    private function parseResult(string $stdout): ?array
    {
        $lines = array_filter(array_map('trim', explode("\n", $stdout)), static fn (string $l) => $l !== '');
        $last = end($lines);
        if ($last === false) {
            return null;
        }
        $data = json_decode($last, true);
        return is_array($data) && isset($data['sites']) ? $data : null;
    }

    /**
     * Gracefully reload php-fpm for the PHP versions of activated sites. Version keys come
     * from user-controlled output, so only existing CustomBuild versions are accepted.
     *
     * @return list<string>
     */
    private function reloadFpm(array $activated): array
    {
        $versions = [];
        foreach ($activated as $a) {
            $key = is_array($a) ? (string) ($a['php'] ?? '') : '';
            $v = $key === 'default' ? $this->defaultPhpVersion() : $key;
            if ($v !== null && preg_match('/^\d{2,3}$/', $v) === 1 && ($this->env->testMode || is_dir('/usr/local/php' . $v))) {
                $versions[$v] = true;
            }
        }
        $out = [];
        foreach (array_keys($versions) as $v) {
            $service = 'php-fpm' . $v;
            if ($this->env->testMode) {
                $out[] = "{$service}: would reload (test mode)";
                continue;
            }
            $systemctl = Proc::which(['/usr/bin/systemctl', '/bin/systemctl']);
            if ($systemctl === null) {
                $out[] = "{$service}: systemctl not found";
                continue;
            }
            [$code, $output] = Proc::run([$systemctl, 'reload', $service]);
            $out[] = $service . ': ' . ($code === 0 ? 'reloaded' : 'reload failed: ' . trim($output));
        }
        return $out;
    }

    private function defaultPhpVersion(): ?string
    {
        [$code, $out] = Proc::run([$this->env->phpCli(), '-n', '-r', 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;']);
        $v = trim($out);
        return $code === 0 && preg_match('/^\d{2,3}$/', $v) === 1 ? $v : null;
    }

    private static function sanitizeSites(mixed $sites): array
    {
        $out = [];
        $str = static fn (mixed $v, int $len = 200): ?string => is_scalar($v) ? substr((string) $v, 0, $len) : null;
        $int = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;
        foreach (is_array($sites) ? array_slice($sites, 0, 200) : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $out[] = [
                'id' => $str($s['id'] ?? null, 64),
                'hostname' => $str($s['hostname'] ?? null, 253),
                'environment' => ($s['environment'] ?? '') === 'production' ? 'production' : 'testing',
                'repo' => $str($s['repo'] ?? null, 150),
                'source' => $str($s['source'] ?? null, 220),
                'auto_deploy' => (bool) ($s['auto_deploy'] ?? false),
                'current_release' => $str($s['current_release'] ?? null, 20),
                'current_label' => $str($s['current_label'] ?? null, 200),
                'current_sha' => $str($s['current_sha'] ?? null, 40),
                'last_status' => $str($s['last_status'] ?? null, 40),
                'last_at' => $int($s['last_at'] ?? null),
                'last_error' => $str($s['last_error'] ?? null, 500),
                'last_poll' => $int($s['last_poll'] ?? null),
                'poll_error' => $str($s['poll_error'] ?? null, 500),
            ];
        }
        return $out;
    }

    /**
     * The runner's nvm Node.js (Toolchain::nvmNode()). The runner's output is user-controlled,
     * so only a plain version number passes.
     *
     * @return array{version:string,npm:bool}|null
     */
    private static function sanitizeNvm(mixed $nvm): ?array
    {
        $version = is_array($nvm) ? ($nvm['version'] ?? null) : null;
        return is_string($version) && preg_match('/^\d{1,4}\.\d{1,6}\.\d{1,6}$/', $version) === 1
            ? ['version' => $version, 'npm' => ($nvm['npm'] ?? false) === true]
            : null;
    }

    public static function statusDir(Env $env): string
    {
        return $env->dataDir . '/status';
    }

    public static function writeStatus(Env $env, string $name, array $data): void
    {
        $dir = self::statusDir($env);
        Fs::ensureDir($dir, 0750);
        $file = $dir . '/' . $name . '.json';
        Store::write($file, $data, 0640);
        // Readable by the account admin pages run as: whoever install.sh gave data/ to.
        $owner = @stat($env->dataDir);
        if (!$env->testMode && posix_geteuid() === 0 && is_array($owner)) {
            @chown($file, (int) $owner['uid']);
            @chgrp($file, (int) $owner['gid']);
        }
    }
}
