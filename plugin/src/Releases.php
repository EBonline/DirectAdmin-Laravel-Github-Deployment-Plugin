<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * The releases/ + shared/ + current layout of a site:
 *   <base>/releases/<ymdHi>/   one directory per deploy, e.g. 2611261413
 *   <base>/shared/             paths linked into every release
 *   <base>/current -> releases/<id>
 *
 * A second deploy in the same minute gets a suffix (2611261413-2). Releases made before
 * the short names (14 digits, YmdHis) are still recognised and count as older.
 */
final class Releases
{
    public const ID_PATTERN = '/^(?:\d{10}|\d{14})(?:-\d{1,3})?$/';

    public function __construct(private string $base)
    {
    }

    public function releasesDir(): string
    {
        return $this->base . '/releases';
    }

    public function sharedDir(): string
    {
        return $this->base . '/shared';
    }

    public function currentLink(): string
    {
        return $this->base . '/current';
    }

    public function ensureLayout(): void
    {
        Fs::ensureDir($this->base, 0755);
        Fs::ensureDir($this->releasesDir(), 0755);
        Fs::ensureDir($this->sharedDir(), 0755);
        $current = $this->currentLink();
        if (file_exists($current) && !is_link($current)) {
            throw new \RuntimeException("{$current} exists and is not a symlink. Move it away before deploying.");
        }
    }

    public function currentId(): ?string
    {
        $target = @readlink($this->currentLink());
        if (!is_string($target)) {
            return null;
        }
        $id = basename($target);
        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** @return list<string> release ids, oldest first */
    public function all(): array
    {
        $ids = [];
        foreach (@scandir($this->releasesDir()) ?: [] as $entry) {
            if (preg_match(self::ID_PATTERN, $entry) === 1 && is_dir($this->releasesDir() . '/' . $entry)) {
                $ids[] = $entry;
            }
        }
        usort($ids, static fn (string $a, string $b): int => self::sortKey($a) <=> self::sortKey($b));
        return $ids;
    }

    /**
     * A new release id for this minute. $taken holds ids already used elsewhere (history
     * entries and their logs), so an id is not reused after its folder was removed.
     *
     * @param list<string> $taken
     */
    public function newId(array $taken = []): string
    {
        $stamp = date('ymdHi');
        $id = $stamp;
        for ($n = 2; in_array($id, $taken, true) || Fs::exists($this->path($id)); $n++) {
            $id = $stamp . '-' . $n;
        }
        return $id;
    }

    /** Oldest first: legacy 14-digit ids, then short ids; by time, then by suffix. */
    private static function sortKey(string $id): array
    {
        [$stamp, $suffix] = array_pad(explode('-', $id, 2), 2, '1');
        return [strlen($stamp) === 14 ? 0 : 1, $stamp, (int) $suffix];
    }

    public function path(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException("Invalid release id {$id}");
        }
        return $this->releasesDir() . '/' . $id;
    }

    /** Switch current to $id; returns the previously active release. */
    public function activate(string $id): ?string
    {
        $path = $this->path($id);
        if (!is_dir($path)) {
            throw new \RuntimeException("Release {$id} does not exist");
        }
        $previous = $this->currentId();
        Fs::atomicSymlink('releases/' . $id, $this->currentLink());
        return $previous;
    }

    /**
     * Keep the newest $keep releases plus anything in $protect.
     *
     * @return list<string> removed ids
     */
    public function cleanup(int $keep, array $protect = []): array
    {
        $all = $this->all();
        $keepIds = array_slice($all, -max(1, $keep));
        $removed = [];
        foreach ($all as $id) {
            if (in_array($id, $keepIds, true) || in_array($id, $protect, true)) {
                continue;
            }
            Fs::remove($this->path($id));
            $removed[] = $id;
        }
        return $removed;
    }

    public function remove(string $id): void
    {
        Fs::remove($this->path($id));
    }
}
