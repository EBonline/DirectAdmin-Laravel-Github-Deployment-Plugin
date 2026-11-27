<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Who may use the plugin. A deploy script runs arbitrary commands as the user, so by
 * default only users with SSH access (a real login shell) are allowed. The admin can
 * override per user with data/access/<user> containing "allow" or "deny"; that directory
 * is 0711 so users can only look up their own name.
 */
final class Access
{
    public const NO_SHELLS = ['', '/bin/false', '/usr/bin/false', '/sbin/nologin', '/usr/sbin/nologin', '/bin/nologin'];
    public const USER_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,31}$/';

    public static function dir(Env $env): string
    {
        return $env->dataDir . '/access';
    }

    public static function override(Env $env, string $user): ?string
    {
        if (preg_match(self::USER_PATTERN, $user) !== 1) {
            return null;
        }
        $file = self::dir($env) . '/' . $user;
        if (!is_file($file)) {
            return null;
        }
        $value = trim((string) @file_get_contents($file));
        return in_array($value, ['allow', 'deny'], true) ? $value : null;
    }

    public static function hasShell(string $shell): bool
    {
        return !in_array($shell, self::NO_SHELLS, true);
    }

    public static function isAllowed(Env $env, Settings $settings, string $user, string $shell): bool
    {
        $override = self::override($env, $user);
        if ($override !== null) {
            return $override === 'allow';
        }
        return match ($settings->get('access_policy')) {
            'all' => true,
            'allowlist' => false,
            default => self::hasShell($shell),
        };
    }

    /** @return array<string,string> user => allow|deny */
    public static function overrides(Env $env): array
    {
        $out = [];
        foreach (@scandir(self::dir($env)) ?: [] as $entry) {
            if (preg_match(self::USER_PATTERN, $entry) !== 1) {
                continue;
            }
            $value = self::override($env, $entry);
            if ($value !== null) {
                $out[$entry] = $value;
            }
        }
        ksort($out);
        return $out;
    }

    public static function setOverride(Env $env, string $user, ?string $value): void
    {
        if (preg_match(self::USER_PATTERN, $user) !== 1) {
            throw new \InvalidArgumentException('Invalid username.');
        }
        $file = self::dir($env) . '/' . $user;
        if ($value === null) {
            if (is_file($file) && !@unlink($file)) {
                throw new \RuntimeException("Could not remove {$file}");
            }
            return;
        }
        if (!in_array($value, ['allow', 'deny'], true)) {
            throw new \InvalidArgumentException('Invalid access value.');
        }
        Fs::ensureDir(self::dir($env), 0711);
        Fs::writeAtomic($file, $value . "\n", 0644);
    }
}
