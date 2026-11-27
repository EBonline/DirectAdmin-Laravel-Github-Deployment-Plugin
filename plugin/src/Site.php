<?php

declare(strict_types=1);

namespace GitDeploy;

final class Site
{
    public string $id = '';
    public string $hostname = '';
    public string $basePath = '';
    public string $webDir = 'public';
    public string $environment = 'testing';
    public string $repo = '';
    public string $branch = 'main';
    public bool $autoDeploy = true;
    public string $php = 'default';
    /** @var list<string> */
    public array $sharedPaths = ['.env', 'storage'];
    public int $keepReleases = 5;
    public string $script = '';
    public int $createdAt = 0;

    public static function fromArray(array $a): self
    {
        $s = new self();
        $s->id = (string) ($a['id'] ?? '');
        $s->hostname = (string) ($a['hostname'] ?? '');
        $s->basePath = (string) ($a['base_path'] ?? '');
        $s->webDir = (string) ($a['web_dir'] ?? 'public');
        $s->environment = ($a['environment'] ?? '') === 'production' ? 'production' : 'testing';
        $s->repo = (string) ($a['repo'] ?? '');
        $s->branch = (string) ($a['branch'] ?? 'main');
        $s->autoDeploy = (bool) ($a['auto_deploy'] ?? true);
        $s->php = (string) ($a['php'] ?? 'default');
        $s->sharedPaths = array_values(array_filter(array_map('strval', (array) ($a['shared_paths'] ?? [])), static fn (string $p) => Fs::isSafeRelative($p)));
        $s->keepReleases = max(1, (int) ($a['keep_releases'] ?? 5));
        $s->script = (string) ($a['script'] ?? '');
        $s->createdAt = (int) ($a['created_at'] ?? 0);
        return $s;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'base_path' => $this->basePath,
            'web_dir' => $this->webDir,
            'environment' => $this->environment,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'auto_deploy' => $this->autoDeploy,
            'php' => $this->php,
            'shared_paths' => $this->sharedPaths,
            'keep_releases' => $this->keepReleases,
            'script' => $this->script,
            'created_at' => $this->createdAt,
        ];
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function currentPath(): string
    {
        return $this->basePath . '/current';
    }

    /** The path the domain's document root must point to. */
    public function docrootPath(): string
    {
        return $this->webDir === '.' ? $this->currentPath() : $this->currentPath() . '/' . $this->webDir;
    }

    public function sharedDir(): string
    {
        return $this->basePath . '/shared';
    }

    /** What this site follows, for display. */
    public function sourceLabel(): string
    {
        return $this->isProduction() ? 'latest release' : 'branch ' . $this->branch;
    }
}
