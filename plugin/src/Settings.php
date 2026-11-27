<?php

declare(strict_types=1);

namespace GitDeploy;

/** Server-wide settings managed from the admin page (data/settings.json). */
final class Settings
{
    public const DEFAULTS = [
        'poll_interval' => 5,        // minutes between GitHub checks per site
        'keep_releases' => 5,        // default releases kept per site
        'max_keep_releases' => 20,   // upper bound a user may choose
        'deploy_timeout' => 15,      // minutes per deploy script
        'reload_fpm' => true,        // gracefully reload PHP-FPM after activation
        'max_parallel' => 4,         // users processed at the same time
        'access_policy' => 'ssh',    // ssh | all | allowlist
        'node_path' => '',           // Node.js for deploy scripts ('' = from PATH)
        'npm_path' => '',            // npm for deploy scripts ('' = next to node_path, else from PATH)
    ];

    public const POLICIES = [
        'ssh' => 'Users with SSH access',
        'all' => 'All users',
        'allowlist' => 'Only users on the allow list',
    ];

    private function __construct(private array $values)
    {
    }

    public static function load(Env $env): self
    {
        $stored = Store::read(self::file($env));
        [$values] = self::validate(array_replace(self::DEFAULTS, $stored));
        return new self($values);
    }

    public static function file(Env $env): string
    {
        return $env->dataDir . '/settings.json';
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return array{0:array,1:array<string,string>} clean values and errors
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $ranges = [
            'poll_interval' => [1, 60],
            'keep_releases' => [1, 50],
            'max_keep_releases' => [1, 50],
            'deploy_timeout' => [1, 180],
            'max_parallel' => [1, 32],
        ];
        $out = self::DEFAULTS;
        foreach ($ranges as $key => [$min, $max]) {
            $raw = $input[$key] ?? self::DEFAULTS[$key];
            if (!is_numeric($raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[$key] = "Must be a number between {$min} and {$max}.";
                continue;
            }
            $out[$key] = (int) $raw;
        }
        if ($out['keep_releases'] > $out['max_keep_releases']) {
            $errors['keep_releases'] = 'Cannot be higher than the maximum.';
            $out['keep_releases'] = $out['max_keep_releases'];
        }
        $out['reload_fpm'] = filter_var($input['reload_fpm'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $policy = (string) ($input['access_policy'] ?? 'ssh');
        if (!isset(self::POLICIES[$policy])) {
            $errors['access_policy'] = 'Unknown policy.';
            $policy = 'ssh';
        }
        $out['access_policy'] = $policy;
        // Only the form here: this runs on every load. Toolchain::checkOverride() runs them on save.
        foreach (['node_path', 'npm_path'] as $key) {
            $path = trim((string) ($input[$key] ?? ''));
            if ($path !== '' && (preg_match('~^/[^\x00-\x1f\x7f]*$~', $path) !== 1 || strlen($path) > 1024 || preg_match('~(^|/)\.\.?(/|$)~', $path) === 1)) {
                $errors[$key] = 'Enter an absolute path without . or .. parts, for example /opt/alt/alt-nodejs22/root/usr/bin/' . ($key === 'node_path' ? 'node' : 'npm') . '.';
                $path = '';
            }
            $out[$key] = $path;
        }
        return [$out, $errors];
    }

    public static function save(Env $env, array $values): void
    {
        Store::write(self::file($env), $values, 0644);
    }
}
