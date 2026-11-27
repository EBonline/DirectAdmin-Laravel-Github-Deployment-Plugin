<?php

declare(strict_types=1);

namespace GitDeploy;

/** Discovers the PHP versions installed by CustomBuild (/usr/local/phpXY). */
final class Php
{
    /** @return array<string,string> key ("default", "83", …) => binary */
    public static function versions(Env $env): array
    {
        $out = ['default' => $env->phpCli()];
        foreach (glob($env->phpDirGlob(), GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('~/php(\d)(\d{1,2})$~', $dir, $m) !== 1) {
                continue;
            }
            $key = $m[1] . $m[2];
            $bin = Proc::which([$dir . '/bin/php', $dir . '/bin/php' . $key]);
            if ($bin !== null) {
                $out[$key] = $bin;
            }
        }
        return $out;
    }

    public static function label(string $key): string
    {
        if ($key === 'default') {
            return 'Server default';
        }
        return 'PHP ' . substr($key, 0, 1) . '.' . substr($key, 1);
    }

    public static function binary(Env $env, string $key): string
    {
        return self::versions($env)[$key] ?? $env->phpCli();
    }

    /** Composer phar, from CustomBuild or bundled by install.sh. */
    public static function composer(Env $env): ?string
    {
        $override = $env->testOverride('GIT_DEPLOY_COMPOSER');
        if ($override !== null) {
            return $override;
        }
        foreach (['/usr/local/bin/composer', $env->pluginDir . '/bin/composer.phar'] as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }
}
