<?php

declare(strict_types=1);

namespace GitDeploy;

final class Fs
{
    public static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    public static function ensureDir(string $dir, int $mode = 0700): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create directory {$dir}");
        }
    }

    /** Remove a file, symlink or directory tree. Symlinks are removed, never followed. */
    public static function remove(string $path): void
    {
        if (!self::exists($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException("Could not remove {$path}");
            }
            return;
        }
        [$code, $out] = Proc::run(['rm', '-rf', '--', $path]);
        if ($code !== 0) {
            throw new \RuntimeException("Could not remove {$path}: " . trim($out));
        }
    }

    /** Point $link at $target atomically (rename over the old link). */
    public static function atomicSymlink(string $target, string $link): void
    {
        if (self::exists($link) && !is_link($link)) {
            throw new \RuntimeException("{$link} exists and is not a symlink; move it away first");
        }
        $tmp = $link . '.tmp-' . bin2hex(random_bytes(4));
        if (!@symlink($target, $tmp)) {
            throw new \RuntimeException("Could not create symlink {$tmp}");
        }
        if (!@rename($tmp, $link)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not switch {$link} to {$target}");
        }
    }

    /** Write a file atomically with the given mode. */
    public static function writeAtomic(string $path, string $contents, int $mode = 0600): void
    {
        $dir = dirname($path);
        self::ensureDir($dir);
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException("Could not write {$path}");
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not write {$path}");
        }
    }

    /**
     * Normalise an absolute path ("//", "/./"). Returns null for relative paths
     * or paths containing "..".
     */
    public static function normalize(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/') {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }

    public static function isWithin(string $path, string $dir): bool
    {
        $dir = rtrim($dir, '/');
        return $path === $dir || str_starts_with($path, $dir . '/');
    }

    /** realpath() of the deepest existing ancestor of $path (inclusive). */
    public static function realAncestor(string $path): ?string
    {
        $p = $path;
        while ($p !== '/' && $p !== '' && !self::exists($p)) {
            $p = dirname($p);
        }
        $real = realpath($p);
        return $real === false ? null : $real;
    }

    /** Valid relative path inside a release: no "..", no leading slash, safe characters. */
    public static function isSafeRelative(string $path): bool
    {
        if ($path === '' || strlen($path) > 255) {
            return false;
        }
        if (preg_match('~^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$~', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $seg) {
            if ($seg === '.' || $seg === '..') {
                return false;
            }
        }
        return true;
    }
}
