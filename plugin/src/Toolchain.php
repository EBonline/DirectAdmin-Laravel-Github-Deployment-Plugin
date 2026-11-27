<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Finds the tools deploy scripts rely on (PHP, Composer, Node.js, npm) on the PATH deploy
 * scripts start with, so the deploy log, the site form and the admin diagnostics all agree
 * with what a deployment will see.
 */
final class Toolchain
{
    /** System part of the deploy PATH; the same for every account. */
    public const SYSTEM_PATH = '/usr/local/bin:/usr/bin:/bin';

    /** Oldest Node.js major version $REQUIRE_NODE() accepts. */
    public const MIN_NODE_MAJOR = 22;

    private const VERSION_TIMEOUT = 15;
    private const ANY_VERSION = '/(\d+\.\d+(?:\.\d+)?)/';

    /**
     * The PATH deploy scripts start with: the selected PHP first, then the admin's Node.js
     * override (see shims()), then the user's own bin directories and the Node.js the user
     * installed with nvm (see nvmBin()), then the system.
     */
    public static function deployPath(string $phpBinary, string $home, ?string $shimDir = null): string
    {
        $nvm = self::nvmBin($home);
        return dirname($phpBinary) . ':' . ($shimDir !== null ? $shimDir . ':' : '')
            . $home . '/.local/bin:' . $home . '/bin:' . ($nvm !== null ? $nvm . ':' : '') . self::SYSTEM_PATH;
    }

    /** Where the user installed nvm: ~/.nvm, or ~/.config/nvm (nvm's XDG location); null without nvm. */
    public static function nvmDir(string $home): ?string
    {
        foreach ([$home . '/.nvm', $home . '/.config/nvm'] as $dir) {
            if (is_file($dir . '/nvm.sh') || is_dir($dir . '/versions/node')) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * Node.js versions installed with nvm ("24.18.0"), newest first.
     *
     * @return list<string>
     */
    public static function nvmVersions(string $nvmDir): array
    {
        $versions = [];
        foreach (glob($nvmDir . '/versions/node/v*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('~/v(\d+\.\d+\.\d+)$~', $dir, $m) === 1 && is_file($dir . '/bin/node') && is_executable($dir . '/bin/node')) {
                $versions[] = $m[1];
            }
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
        return $versions;
    }

    /**
     * The bin directory (node, npm, npx) of the nvm Node.js deploy scripts use, whatever
     * versions are installed: nvm's default alias when it is new enough, else the newest
     * version that is, else the newest (so $REQUIRE_NODE() can name its version). Null
     * without nvm or without installed versions.
     */
    public static function nvmBin(string $home): ?string
    {
        $dir = self::nvmDir($home);
        $versions = $dir !== null ? self::nvmVersions($dir) : [];
        if ($versions === []) {
            return null;
        }
        $default = is_file($dir . '/alias/default')
            ? self::nvmAlias($dir, (string) file_get_contents($dir . '/alias/default'), $versions)
            : null;
        $supported = array_values(array_filter($versions, [self::class, 'nodeSupported']));
        $pick = $default !== null && self::nodeSupported($default) ? $default : ($supported[0] ?? $default ?? $versions[0]);
        return $dir . '/versions/node/v' . $pick . '/bin';
    }

    /**
     * The nvm Node.js deploy scripts in $home use (see nvmBin()): its version, and whether npm
     * is next to it. Null without one. Reads only the file system and starts no process, so
     * the runner can report it every minute for the admin overview.
     *
     * @return array{version:string,npm:bool}|null
     */
    public static function nvmNode(string $home): ?array
    {
        $bin = self::nvmBin($home);
        if ($bin === null || preg_match('~/v(\d+\.\d+\.\d+)/bin$~', $bin, $m) !== 1) {
            return null;
        }
        return ['version' => $m[1], 'npm' => is_file($bin . '/npm') && is_executable($bin . '/npm')];
    }

    /**
     * Resolves an nvm alias to an installed version: "24", "v22.11", "node", or an alias file
     * such as alias/lts/* → "lts/jod" → alias/lts/jod → "v22.11.0".
     *
     * @param list<string> $versions installed, newest first
     */
    private static function nvmAlias(string $nvmDir, string $name, array $versions, int $depth = 0): ?string
    {
        $name = trim($name);
        if ($name === '' || $depth > 5) {
            return null;
        }
        if ($name === 'node' || $name === 'stable') {
            return $versions[0];
        }
        if (preg_match('/^v?(\d+(?:\.\d+){0,2})$/', $name, $m) === 1) {
            foreach ($versions as $v) {
                if ($v === $m[1] || str_starts_with($v, $m[1] . '.')) {
                    return $v;
                }
            }
            return null;
        }
        if (preg_match('~^[A-Za-z0-9_.*/-]+$~', $name) !== 1 || str_contains($name, '..')) {
            return null;
        }
        $file = $nvmDir . '/alias/' . $name;
        return is_file($file) ? self::nvmAlias($nvmDir, (string) file_get_contents($file), $versions, $depth + 1) : null;
    }

    /**
     * The admin's Node.js/npm override (settings node_path, npm_path), resolved: without an
     * npm path, the npm next to the configured Node.js is used (npm-22 next to node-22, else
     * npm); npx is taken from next to npm the same way.
     *
     * @param array<string,mixed> $settings
     * @return array{node:?string,npm:?string,npx:?string}
     */
    public static function configured(array $settings): array
    {
        $node = (string) ($settings['node_path'] ?? '');
        $npm = (string) ($settings['npm_path'] ?? '');
        $node = $node !== '' ? $node : null;
        $npm = $npm !== '' ? $npm : ($node !== null ? self::sibling($node, 'node', 'npm') : null);
        return ['node' => $node, 'npm' => $npm, 'npx' => $npm !== null ? self::sibling($npm, 'npm', 'npx') : null];
    }

    /**
     * Keeps $dir in step with the admin's override: `node`, `npm` and `npx` symlinks to the
     * configured files, so they work whatever those are called (node-22, …). Returns $dir
     * while it holds a link (put it on PATH), null when there is no override.
     *
     * @param array{node:?string,npm:?string,npx:?string} $targets from configured()
     */
    public static function shims(string $dir, array $targets): ?string
    {
        $any = false;
        foreach (['node', 'npm', 'npx'] as $name) {
            $link = $dir . '/' . $name;
            $target = $targets[$name] ?? null;
            if ($target === null) {
                if (is_link($link)) {
                    @unlink($link);
                }
                continue;
            }
            $any = true;
            if (is_link($link) && readlink($link) === $target) {
                continue;
            }
            Fs::ensureDir($dir);
            $tmp = $link . '.tmp' . getmypid();
            @unlink($tmp);
            if (!@symlink($target, $tmp) || !@rename($tmp, $link)) {
                @unlink($tmp);
                throw new \RuntimeException("Could not link {$link} to {$target}.");
            }
        }
        return $any ? $dir : null;
    }

    /**
     * Runs the admin's override before it is saved: errors per settings field, and on success
     * a sentence saying what deploy scripts will use. Slow (starts npm), so only on save.
     *
     * @param array<string,mixed> $settings validated settings (see Settings::validate())
     * @return array{0:array<string,string>,1:?string}
     */
    public static function checkOverride(array $settings): array
    {
        $nodePath = (string) ($settings['node_path'] ?? '');
        $npmPath = (string) ($settings['npm_path'] ?? '');
        if ($nodePath === '' && $npmPath === '') {
            return [[], null];
        }
        $errors = [];
        $nodeVersion = null;
        if ($nodePath !== '') {
            $errors['node_path'] = self::runnable($nodePath);
            if ($errors['node_path'] === null) {
                $nodeVersion = self::version([$nodePath], ['PATH' => self::SYSTEM_PATH]);
                $errors['node_path'] = match (true) {
                    $nodeVersion === null => 'Running it with --version failed; this does not look like a working Node.js.',
                    !self::nodeSupported($nodeVersion) => "This is Node.js {$nodeVersion}; deploy scripts need " . self::MIN_NODE_MAJOR . ' or newer.',
                    default => null,
                };
            }
        }
        if ($npmPath !== '') {
            $errors['npm_path'] = self::runnable($npmPath);
        }
        $errors = array_filter($errors, static fn (?string $e): bool => $e !== null);
        $cfg = self::configured($settings);
        $npmVersion = null;
        if ($errors === [] && $cfg['npm'] !== null) {
            // npm runs on whichever node comes first on PATH: the configured one, else the system's.
            $tmp = sys_get_temp_dir() . '/gd-node-' . bin2hex(random_bytes(6));
            try {
                Fs::ensureDir($tmp);
                $node = $cfg['node'] ?? self::which('node', self::SYSTEM_PATH);
                if ($node !== null) {
                    @symlink($node, $tmp . '/node');
                }
                $npmVersion = self::version([$cfg['npm']], ['PATH' => $tmp . ':' . self::SYSTEM_PATH, 'HOME' => $tmp]);
            } finally {
                Fs::remove($tmp);
            }
            if ($npmVersion === null) {
                $errors[$npmPath !== '' ? 'npm_path' : 'node_path'] = "{$cfg['npm']} --version failed"
                    . ($npmPath === '' ? ' (the npm next to this Node.js; enter the npm path to use another one).' : '.');
            }
        }
        if ($errors !== []) {
            return [$errors, null];
        }
        $parts = [];
        if ($cfg['node'] !== null) {
            $parts[] = "Node.js {$nodeVersion} ({$cfg['node']})";
        }
        $parts[] = $cfg['npm'] !== null ? "npm {$npmVersion} ({$cfg['npm']})" : 'npm from the system PATH';
        return [[], 'Deploy scripts now use ' . implode(' and ', $parts) . '.'];
    }

    /** Why users could not run $path, or null when they can. */
    private static function runnable(string $path): ?string
    {
        $real = realpath($path);
        return match (true) {
            $real === false || !is_file($real) => 'There is no file at this path.',
            !is_executable($real) => 'This file is not executable.',
            (fileperms($real) & 0o001) === 0 => 'Users cannot run this file: it is not executable for others (chmod o+x).',
            default => null,
        };
    }

    /** The executable next to $file named like it with $from replaced by $to (node-22 → npm-22), else plain $to. */
    private static function sibling(string $file, string $from, string $to): ?string
    {
        $dir = dirname($file);
        foreach (array_unique([str_replace($from, $to, basename($file)), $to]) as $name) {
            if (is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) {
                return $dir . '/' . $name;
            }
        }
        return null;
    }

    /** First executable called $name in the absolute directories of $path. */
    public static function which(string $name, string $path): ?string
    {
        if ($name === '' || str_contains($name, '/')) {
            return null;
        }
        foreach (explode(':', $path) as $dir) {
            if ($dir === '' || $dir[0] !== '/') {
                continue; // relative entries depend on the working directory
            }
            $file = rtrim($dir, '/') . '/' . $name;
            if (is_file($file) && is_executable($file)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * The version `<cmd> --version` prints ("22.11.0" for "v22.11.0"), or null when the
     * command fails or prints none.
     *
     * @param list<string> $cmd
     * @param array<string,string>|null $env full environment (null = inherit)
     */
    public static function version(array $cmd, ?array $env, ?string $cwd = null, string $pattern = self::ANY_VERSION): ?string
    {
        $cmd[] = '--version';
        $timeout = Proc::which(['/usr/bin/timeout', '/bin/timeout']);
        if ($timeout !== null) {
            array_unshift($cmd, $timeout, (string) self::VERSION_TIMEOUT);
        }
        try {
            [$code, $out] = Proc::capture($cmd, $cwd, $env, 65536);
        } catch (\Throwable) {
            return null;
        }
        return $code === 0 && preg_match($pattern, $out, $m) === 1 ? $m[1] : null;
    }

    /** Whether a Node.js version from version() ("22.11.0") is at least MIN_NODE_MAJOR. */
    public static function nodeSupported(?string $version): bool
    {
        return $version !== null && (int) explode('.', $version)[0] >= self::MIN_NODE_MAJOR;
    }

    /**
     * Node.js and npm as found on $path. `supported` is false when node is older than
     * MIN_NODE_MAJOR or does not report a version. The npm version is only looked up when
     * asked: npm takes a few hundred milliseconds to start.
     *
     * @param array<string,string>|null $env environment for the version commands (null = inherit)
     * @return array{node:?array{path:string,version:?string,supported:bool}, npm:?array{path:string,version:?string}}
     */
    public static function node(string $path, ?array $env, bool $npmVersion = false): array
    {
        $node = self::which('node', $path);
        $npm = self::which('npm', $path);
        $nodeVersion = $node !== null ? self::version([$node], $env) : null;
        return [
            'node' => $node !== null ? ['path' => $node, 'version' => $nodeVersion, 'supported' => self::nodeSupported($nodeVersion)] : null,
            'npm' => $npm !== null ? ['path' => $npm, 'version' => $npmVersion ? self::version([$npm], $env) : null] : null,
        ];
    }

    /**
     * Advice for when no suitable Node.js is on the deploy PATH although a version manager is
     * installed for this account: what was found, and a command to run once over SSH (`ssh`)
     * or a line to add to the deploy script.
     *
     * @param array<string,string> $env the deploy script's environment, when known
     * @return array{text:string,line:string,ssh:bool}|null
     */
    public static function hint(string $home, array $env = []): ?array
    {
        $min = self::MIN_NODE_MAJOR;
        $nvm = self::nvmDir($home);
        if ($nvm !== null) {
            $label = '~/' . substr($nvm, strlen($home) + 1);
            $versions = self::nvmVersions($nvm);
            $supported = array_values(array_filter($versions, [self::class, 'nodeSupported']));
            if ($supported === []) {
                return [
                    'text' => "nvm is installed in {$label}, but " . ($versions === [] ? 'without any Node.js version' : "its newest Node.js is {$versions[0]}"),
                    'line' => "nvm install {$min}",
                    'ssh' => true,
                ];
            }
            // nvm's Node.js is on the deploy PATH already; the script put another one first.
            $major = explode('.', $supported[0])[0];
            return [
                'text' => "nvm has Node.js {$supported[0]} in {$label}, but the deploy script's PATH finds another Node.js first",
                'line' => ($env['NVM_DIR'] ?? '') !== '' ? "nvm use {$major}" : "source {$label}/nvm.sh && nvm use {$major}",
                'ssh' => false,
            ];
        }
        if (is_dir($home . '/.volta/bin')) {
            return ['text' => 'Volta is installed in ~/.volta', 'line' => 'export PATH="$HOME/.volta/bin:$PATH"', 'ssh' => false];
        }
        foreach (['.local/share/fnm', '.fnm'] as $dir) {
            if (is_dir($home . '/' . $dir)) {
                return ['text' => "fnm is installed in ~/{$dir}", 'line' => 'export PATH="$HOME/' . $dir . ':$PATH" && eval "$(fnm env)"', 'ssh' => false];
            }
        }
        return null;
    }

    /** @param array{text:string,line:string,ssh:bool} $hint */
    public static function hintText(array $hint): string
    {
        return $hint['ssh']
            ? $hint['text'] . '. Run this once over SSH: ' . $hint['line']
            : $hint['text'] . '. Add this line to the deploy script, above ' . ScriptBuilder::REQUIRE_NODE . ': ' . $hint['line'];
    }

    /**
     * One line for the deploy log, e.g. "Toolchain: PHP 8.3.14, Composer 2.8.4, Node.js 22.11.0, npm 10.9.0".
     *
     * @param array<string,string> $env the deploy script's environment (its PATH is searched)
     */
    public static function summary(string $php, ?string $composer, array $env, ?string $cwd = null): string
    {
        $path = $env['PATH'] ?? self::SYSTEM_PATH;
        if ($composer !== null) {
            $composerCmd = [$php, $composer, '--no-ansi'];
        } else {
            $bin = self::which('composer', $path);
            $composerCmd = $bin !== null ? [$bin, '--no-ansi'] : null;
        }
        $node = self::node($path, $env, true);
        $parts = [
            'PHP ' . (self::version([$php], $env, $cwd, '/PHP (\d+\.\d+\.\d+)/') ?? 'version unknown'),
            $composerCmd === null
                ? 'Composer not found'
                : 'Composer ' . (self::version($composerCmd, $env, $cwd, '/Composer (?:version )?(\d+\.\d+(?:\.\d+)?)/') ?? 'version unknown'),
            self::describe('Node.js', $node['node']),
            self::describe('npm', $node['npm']),
        ];
        $line = 'Toolchain: ' . implode(', ', $parts);
        if ($node['node'] === null || $node['npm'] === null) {
            $line .= " (PATH: {$path})";
        }
        return $line;
    }

    /** "Install Node.js 22 or newer …": advice when nothing points at a Node.js install. */
    public static function installAdvice(): string
    {
        return 'Install Node.js ' . self::MIN_NODE_MAJOR . ' or newer for this account (for example with nvm), or ask your hosting provider.'
            . ' If this app needs no frontend build, remove the npm lines from the deploy script.';
    }

    /** @param array{path:string,version:?string,supported?:bool}|null $tool */
    private static function describe(string $label, ?array $tool): string
    {
        if ($tool === null) {
            return "{$label} not found";
        }
        $text = $label . ' ' . ($tool['version'] ?? 'version unknown');
        if (isset($tool['supported']) && !$tool['supported'] && $tool['version'] !== null) {
            $text .= " ({$tool['path']}: too old, " . self::MIN_NODE_MAJOR . ' or newer is needed)';
        }
        return $text;
    }
}
