<?php

declare(strict_types=1);

namespace GitDeploy;

/** JSON API of the admin interface (/CMD_PLUGINS_ADMIN/<plugin>/api.raw): overview, settings and access. */
final class AdminApi extends JsonApi
{
    /** The dispatcher runs every minute; older than this means cron is not running. */
    public const CRON_STALE_AFTER = 300;

    public function __construct(private Env $env, Request $req, Csrf $csrf, private string $identity)
    {
        parent::__construct($req, $csrf);
    }

    protected function reads(): array
    {
        return ['overview' => fn (): array => $this->overview()];
    }

    protected function writes(): array
    {
        return [
            'save_settings' => fn (array $p): array => $this->saveSettings($p),
            'set_access' => fn (array $p): array => $this->setAccess($p),
            'remove_access' => fn (array $p): array => $this->removeAccess($p),
        ];
    }

    private function overview(): array
    {
        $users = [];
        $nvm = [];
        foreach (glob(Worker::statusDir($this->env) . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if (str_starts_with($name, '_')) {
                continue;
            }
            $st = Store::read($file);
            if (is_string($st['nvm']['version'] ?? null)) {
                $nvm[$name] = ['version' => $st['nvm']['version'], 'npm' => ($st['nvm']['npm'] ?? false) === true];
            }
            $users[] = [
                'user' => $name,
                'updated_at' => isset($st['updated_at']) ? (int) $st['updated_at'] : null,
                'error' => isset($st['error']) ? (string) $st['error'] : null,
                'sites' => is_array($st['sites'] ?? null) ? array_values($st['sites']) : [],
            ];
        }
        usort($users, static fn (array $a, array $b): int => strcmp($a['user'], $b['user']));
        ksort($nvm, SORT_STRING);
        $dispatcher = Store::read(Worker::statusDir($this->env) . '/_dispatcher.json');
        return [
            'users' => $users,
            'dispatcher' => $dispatcher !== [] ? $dispatcher : null,
            'cron_stale_after' => self::CRON_STALE_AFTER,
            'diagnostics' => $this->diagnostics($nvm),
            'writable' => $this->writable(),
            'identity' => $this->identity,
            'data_owner' => $this->dataOwner(),
            'permission_fix' => self::permissionFix($this->writable(), $this->identity, $this->dataOwner()),
            'status_readable' => $this->statusReadable(),
            'install_command' => $this->installCommand(),
            'version' => $this->env->version(),
            'node_min_major' => Toolchain::MIN_NODE_MAJOR,
        ] + $this->settingsData();
    }

    private function settingsData(): array
    {
        return [
            'settings' => Settings::load($this->env)->all(),
            'policies' => array_map(
                static fn (string $k, string $label): array => ['key' => $k, 'label' => $label],
                array_keys(Settings::POLICIES),
                Settings::POLICIES,
            ),
            'overrides' => $this->overrides(),
        ];
    }

    /** @return list<array{user:string,value:string}> */
    private function overrides(): array
    {
        $out = [];
        foreach (Access::overrides($this->env) as $user => $value) {
            $out[] = ['user' => (string) $user, 'value' => $value];
        }
        return $out;
    }

    private function writable(): bool
    {
        $dir = $this->env->dataDir;
        $settings = Settings::file($this->env);
        return is_dir($dir) && is_writable($dir) && (!is_file($settings) || is_writable($settings));
    }

    /** Account that owns data/ (install.sh gives it to the main admin account), null if unknown. */
    private function dataOwner(): ?string
    {
        $uid = @fileowner($this->env->dataDir);
        if ($uid === false) {
            return null;
        }
        $pw = posix_getpwuid($uid);
        return is_array($pw) ? (string) $pw['name'] : (string) $uid;
    }

    /** Root writes the overview data in data/status/ for the owner of data/. */
    private function statusReadable(): bool
    {
        $dir = Worker::statusDir($this->env);
        return is_dir($dir) && is_readable($dir) && is_executable($dir);
    }

    private function installCommand(): string
    {
        return 'sh ' . $this->env->pluginDir . '/scripts/install.sh';
    }

    /**
     * What makes an unwritable data directory writable again. DirectAdmin runs admin pages as
     * the logged-in admin's own account, and install.sh gives data/ to the main admin account.
     * 'install': running install.sh as root fixes it (data/ belongs to this account, to a
     * system account, or to nobody yet). 'main_admin': data/ belongs to another admin
     * account, who is the one that can change the settings.
     *
     * @return 'install'|'main_admin'|null null when the directory is writable
     */
    public static function permissionFix(bool $writable, string $identity, ?string $owner): ?string
    {
        if ($writable) {
            return null;
        }
        return $owner === null || $owner === $identity || in_array($owner, ['root', 'diradmin'], true)
            ? 'install'
            : 'main_admin';
    }

    /** Why settings cannot be saved, and what to do about it. */
    private function notWritableMessage(): string
    {
        $owner = $this->dataOwner();
        if (self::permissionFix(false, $this->identity, $owner) === 'main_admin') {
            return "Settings can only be changed by {$owner}, the main DirectAdmin admin account. Log in as {$owner} to change them.";
        }
        return 'The data directory ' . ($owner !== null && $owner !== $this->identity ? "belongs to {$owner}, but admin pages run as {$this->identity}" : 'is not writable')
            . '. Run ' . $this->installCommand() . ' as root to fix its permissions.';
    }

    /**
     * Rows marked optional are shown as a warning, not counted as a problem.
     *
     * @param array<string,array{version:string,npm:bool}> $nvm see nodeRows()
     * @return list<array{label:string,value:string,ok:bool,optional:bool}>
     */
    private function diagnostics(array $nvm): array
    {
        $runuser = Proc::which(['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser']);
        $composer = Php::composer($this->env);
        $versions = Php::versions($this->env);
        unset($versions['default']);
        $git = Git::version();
        $cron = is_file(AdminApp::CRON_FILE);
        $rows = [
            ['Plugin version', $this->env->version(), true],
            ['Admin scripts run as', $this->identity, true],
            ['Data directory', $this->env->dataDir . ($this->writable() ? ' (writable)' : ' — NOT writable: owned by ' . ($this->dataOwner() ?? 'nobody (missing)') . ", admin pages run as {$this->identity}"), $this->writable()],
            ['Cron job', $cron ? AdminApp::CRON_FILE : 'missing — reinstall the plugin', $cron],
            ['git', $git ?? 'not found', $git !== null],
            ['runuser', $runuser ?? 'not found', $runuser !== null],
            ['PHP curl extension', extension_loaded('curl') ? 'loaded' : 'missing', extension_loaded('curl')],
            ['PHP versions', $versions !== [] ? implode(', ', array_map([Php::class, 'label'], array_map('strval', array_keys($versions)))) : 'only the server default', true],
            ['Composer', $composer ?? 'not found (deploy scripts fall back to "composer" on PATH)', $composer !== null],
            ...$this->nodeRows($nvm),
        ];
        return array_map(
            static fn (array $r): array => ['label' => $r[0], 'value' => $r[1], 'ok' => $r[2], 'optional' => $r[3] ?? false],
            $rows,
        );
    }

    /**
     * Node.js and npm for deploy scripts: the override from the settings, else what the system
     * part of the deploy PATH offers and what users installed with nvm, which comes before it.
     * This account cannot look in users' home directories, so the nvm versions come from each
     * user's runner (status files, $nvm per user). Optional unless the admin set a path. Only
     * `node --version` runs: this page is polled.
     *
     * @param array<string,array{version:string,npm:bool}> $nvm
     */
    private function nodeRows(array $nvm): array
    {
        $settings = Settings::load($this->env)->all();
        $admin = Toolchain::configured($settings);
        $min = Toolchain::MIN_NODE_MAJOR;
        $system = Toolchain::SYSTEM_PATH;
        $node = $admin['node'] ?? Toolchain::which('node', $system);
        $runnable = $node !== null && is_file($node) && is_executable($node);
        $version = $runnable ? Toolchain::version([$node], ['PATH' => $system]) : null;
        $nodeOk = Toolchain::nodeSupported($version);
        // The override comes first on every deploy PATH, so then nvm does not count.
        $nvmNode = $admin['node'] === null ? $nvm : [];
        $nvmOk = $nvmNode !== [] && array_filter($nvmNode, static fn (array $n): bool => !Toolchain::nodeSupported($n['version'])) === [];
        $nvmList = self::userList(array_map(
            static fn (array $n): string => $n['version'] . (Toolchain::nodeSupported($n['version']) ? '' : " (too old: nvm install {$min})"),
            $nvmNode,
        ));
        $nodeValue = match (true) {
            $nodeOk => "{$version} ({$node})",
            $version !== null => "{$version} ({$node}) — too old: deploy scripts need Node.js {$min} or newer",
            $runnable => "{$node} found, but node --version failed",
            $node !== null => "{$node} (from the settings below) does not exist or is not executable",
            $nvmNode !== [] => "not on {$system}; users installed it with nvm: {$nvmList}",
            default => "not found on {$system}, and no user has installed it with nvm — only needed by deploy scripts that build frontend assets (Node.js {$min} or newer); set a path below, or users can install it with nvm",
        };
        if ($node !== null && $nvmNode !== []) {
            $nodeValue .= "; users' own nvm comes first on their deploy PATH: {$nvmList}";
        }
        if ($admin['node'] !== null && $nodeOk) {
            $nodeValue .= ', set below';
        }

        $npm = $admin['npm'] ?? Toolchain::which('npm', $system);
        $npmOk = $npm !== null && is_file($npm) && is_executable($npm);
        $nvmNpm = $admin['npm'] === null ? $nvm : [];
        $withNpm = array_keys(array_filter($nvmNpm, static fn (array $n): bool => $n['npm']));
        $withoutNpm = array_keys(array_filter($nvmNpm, static fn (array $n): bool => !$n['npm']));
        $nvmNpmText = implode('; ', array_filter([
            $withNpm !== [] ? 'next to the nvm Node.js of ' . self::userList(array_fill_keys($withNpm, '')) : null,
            $withoutNpm !== [] ? 'missing next to the nvm Node.js of ' . self::userList(array_fill_keys($withoutNpm, '')) : null,
        ]));
        $npmValue = match (true) {
            $npmOk && $settings['npm_path'] !== '' => "{$npm}, set below",
            $npmOk && $admin['npm'] !== null => "{$npm} (next to Node.js)",
            $npmOk => $npm,
            $npm !== null => "{$npm} (from the settings below) does not exist or is not executable",
            $nvmNpm !== [] => "not on {$system}; {$nvmNpmText}",
            default => 'not found on ' . $system . ($admin['node'] !== null ? ' or next to the Node.js set below' : ''),
        };
        return [
            // Without a system Node.js, the users' nvm is all deploy scripts have.
            ['Node.js', $nodeValue, $node === null ? $nvmOk : $nodeOk && ($nvmNode === [] || $nvmOk), $admin['node'] === null],
            ['npm', $npmValue, $npmOk || $npm === null && $nvmNpm !== [] && $withoutNpm === [], $settings['npm_path'] === '' && $admin['node'] === null],
        ];
    }

    /**
     * "alice 22.11.0, bob 24.1.0 and 3 more": user names, each with a detail ('' for none).
     *
     * @param array<string,string> $byUser
     */
    private static function userList(array $byUser, int $max = 5): string
    {
        $parts = [];
        foreach (array_slice($byUser, 0, $max, true) as $user => $detail) {
            $parts[] = trim($user . ' ' . $detail);
        }
        $more = count($byUser) - count($parts);
        return implode(', ', $parts) . ($more > 0 ? " and {$more} more" : '');
    }

    private function requireWritable(): void
    {
        if (!$this->writable()) {
            $otherAdmin = self::permissionFix(false, $this->identity, $this->dataOwner()) === 'main_admin';
            throw new ApiError($otherAdmin ? 403 : 500, 'not_writable', $this->notWritableMessage());
        }
    }

    private function saveSettings(array $p): array
    {
        $this->requireWritable();
        $input = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            if (array_key_exists($key, $p)) {
                $input[$key] = $p[$key];
            }
        }
        $before = Settings::load($this->env)->all();
        [$values, $errors] = Settings::validate($input + $before);
        if ($errors !== []) {
            throw ApiError::validation($errors);
        }
        // Run the Node.js override before saving it, so deployments never start with a broken one.
        [$errors, $nodeSummary] = Toolchain::checkOverride($values);
        if ($errors !== []) {
            throw ApiError::validation($errors, 'The Node.js settings do not work: ' . implode(' ', $errors));
        }
        Settings::save($this->env, $values);
        $message = 'Settings saved. They apply from the next cron run.';
        $nodeChanged = $values['node_path'] !== $before['node_path'] || $values['npm_path'] !== $before['npm_path'];
        if ($nodeChanged) {
            $message .= ' ' . ($nodeSummary ?? 'Deploy scripts find Node.js and npm on PATH again.');
        }
        return ['settings' => $values, 'message' => $message];
    }

    private function setAccess(array $p): array
    {
        $this->requireWritable();
        $user = strtolower(self::str($p, 'user'));
        if (preg_match(Access::USER_PATTERN, $user) !== 1 || !is_dir($this->env->daUsersDir() . '/' . $user)) {
            throw ApiError::validation(['user' => $user === '' ? 'Enter a username.' : "There is no DirectAdmin user {$user}."]);
        }
        $value = self::str($p, 'value') === 'deny' ? 'deny' : 'allow';
        Access::setOverride($this->env, $user, $value);
        return [
            'overrides' => $this->overrides(),
            'message' => $value === 'allow' ? "{$user} may now use Laravel Github deploy." : "{$user} can no longer use Laravel Github deploy.",
        ];
    }

    private function removeAccess(array $p): array
    {
        $this->requireWritable();
        $user = strtolower(self::str($p, 'user'));
        Access::setOverride($this->env, $user, null);
        return ['overrides' => $this->overrides(), 'message' => "Override for {$user} removed; the policy applies again."];
    }
}
