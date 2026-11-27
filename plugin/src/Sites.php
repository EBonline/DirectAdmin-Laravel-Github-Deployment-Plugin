<?php

declare(strict_types=1);

namespace GitDeploy;

/** The user's site configurations (~/.laravel-github-deploy/sites.json). */
final class Sites
{
    public function __construct(private UserData $ud)
    {
    }

    /** @return array<string,Site> */
    public function all(): array
    {
        $data = Store::read($this->ud->sitesFile(), ['sites' => []]);
        $out = [];
        foreach ((array) ($data['sites'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $site = Site::fromArray($row);
            if (preg_match(UserData::ID_PATTERN, $site->id) === 1) {
                $out[$site->id] = $site;
            }
        }
        ksort($out);
        return $out;
    }

    public function get(string $id): ?Site
    {
        return $this->all()[$id] ?? null;
    }

    public function save(Site $site): void
    {
        Store::update($this->ud->sitesFile(), static function (array $data) use ($site): array {
            $data['sites'] = (array) ($data['sites'] ?? []);
            $data['sites'][$site->id] = $site->toArray();
            return $data;
        }, ['sites' => []]);
    }

    public function delete(string $id): void
    {
        Store::update($this->ud->sitesFile(), static function (array $data) use ($id): array {
            unset($data['sites'][$id]);
            return $data;
        }, ['sites' => []]);
    }

    /** Unique id derived from the hostname. */
    public function newId(string $hostname): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($hostname)), '-');
        $base = substr($base !== '' ? $base : 'site', 0, 50);
        $existing = $this->all();
        $id = $base;
        for ($n = 2; isset($existing[$id]); $n++) {
            $id = $base . '-' . $n;
        }
        return $id;
    }
}
