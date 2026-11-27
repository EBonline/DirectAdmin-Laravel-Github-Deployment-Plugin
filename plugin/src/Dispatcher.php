<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Root cron entry point (every minute). Finds DirectAdmin users that may use the
 * plugin and have sites, and starts one background worker per user (bounded by
 * max_parallel). Never reads the content of user-owned files.
 */
final class Dispatcher
{
    public function __construct(private Env $env)
    {
    }

    public function run(bool $foreground = false): int
    {
        if (!$this->env->testMode && posix_geteuid() !== 0) {
            fwrite(STDERR, "dispatcher.php must run as root\n");
            return 1;
        }
        $runDir = $this->env->dataDir . '/run';
        Fs::ensureDir($runDir, 0700);
        $lock = fopen($runDir . '/dispatcher.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }

        $settings = Settings::load($this->env);
        $candidates = $this->eligibleUsers($settings);

        $free = [];
        $running = 0;
        foreach ($candidates as $user) {
            if (Worker::isRunning($this->env, $user)) {
                $running++;
            } else {
                $free[] = $user;
            }
        }
        shuffle($free);
        $slots = max(0, $settings->int('max_parallel') - $running);
        $started = array_slice($free, 0, $slots);
        foreach ($started as $user) {
            $foreground ? (new Worker($this->env))->run($user) : $this->spawn($user);
        }

        Worker::writeStatus($this->env, '_dispatcher', [
            'last_run' => time(),
            'eligible_users' => count($candidates),
            'started' => count($started),
            'busy' => $running,
        ]);
        flock($lock, LOCK_UN);
        fclose($lock);
        return 0;
    }

    /** @return list<string> */
    public function eligibleUsers(Settings $settings): array
    {
        $out = [];
        $dir = $this->env->daUsersDir();
        foreach (@scandir($dir) ?: [] as $user) {
            if (preg_match(Access::USER_PATTERN, $user) !== 1 || !is_file("{$dir}/{$user}/user.conf")) {
                continue;
            }
            $conf = self::parseConf("{$dir}/{$user}/user.conf");
            if (($conf['suspended'] ?? 'no') === 'yes') {
                continue;
            }
            $pw = posix_getpwnam($user);
            if (!is_array($pw) || (int) $pw['uid'] === 0) {
                continue;
            }
            if (!Access::isAllowed($this->env, $settings, $user, (string) $pw['shell'])) {
                continue;
            }
            $home = ($this->env->testMode && (int) $pw['uid'] === posix_geteuid() ? UserData::testHome() : null) ?? (string) $pw['dir'];
            if (!is_file($home . '/.laravel-github-deploy/sites.json')) {
                continue;
            }
            $out[] = $user;
        }
        return $out;
    }

    private function spawn(string $user): void
    {
        $cmd = sprintf(
            'setsid %s -c %s %s %s >/dev/null 2>&1 &',
            escapeshellarg($this->env->phpCli()),
            escapeshellarg($this->env->phpIni()),
            escapeshellarg($this->env->pluginDir . '/bin/worker.php'),
            escapeshellarg($user),
        );
        exec($cmd);
    }

    /** DirectAdmin key=value config file. */
    public static function parseConf(string $file): array
    {
        $out = [];
        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $out[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $out;
    }
}
