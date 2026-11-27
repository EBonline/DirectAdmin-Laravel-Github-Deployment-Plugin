<?php

declare(strict_types=1);

namespace GitDeploy;

/** Small JSON file store with atomic writes and flock-guarded read-modify-write. */
final class Store
{
    public static function read(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    public static function write(string $path, array $data, int $mode = 0600): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException("Could not encode data for {$path}");
        }
        Fs::writeAtomic($path, $json . "\n", $mode);
    }

    /**
     * @param callable(array):array $fn
     */
    public static function update(string $path, callable $fn, array $default = [], int $mode = 0600): array
    {
        Fs::ensureDir(dirname($path));
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Could not lock {$path}");
        }
        @chmod($path . '.lock', $mode);
        try {
            flock($lock, LOCK_EX);
            $data = $fn(self::read($path, $default));
            self::write($path, $data, $mode);
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
