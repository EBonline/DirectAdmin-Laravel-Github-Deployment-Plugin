<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Implements the deploy-script macros. Invoked from the generated bash script as
 * `__laravel_github_deploy <command>`; context comes from GIT_DEPLOY_* environment variables.
 */
final class Helper
{
    public function __construct(private Env $env, private UserData $ud)
    {
    }

    public function run(array $argv): int
    {
        $command = (string) ($argv[1] ?? '');
        try {
            $site = (new Sites($this->ud))->get((string) getenv('GIT_DEPLOY_SITE'));
            if ($site === null) {
                throw new \RuntimeException('Unknown site; this helper only runs inside a Laravel Github deploy deployment.');
            }
            $deployId = (string) getenv('GIT_DEPLOY_ID');
            match ($command) {
                'create-release' => $this->createRelease($site, $deployId),
                'activate-release' => $this->activateRelease($site, $deployId),
                'restart-queues' => $this->restartQueues($site),
                'require-node' => $this->requireNode(),
                default => throw new \RuntimeException("Unknown command '{$command}'."),
            };
            return 0;
        } catch (\Throwable $e) {
            $this->say('ERROR: ' . $e->getMessage());
            return 1;
        }
    }

    private function say(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }

    private function createRelease(Site $site, string $deployId): void
    {
        $sha = (string) getenv('GIT_DEPLOY_SHA');
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new \RuntimeException('Missing commit to deploy.');
        }
        $releases = new Releases($site->basePath);
        $dir = $releases->path($deployId);
        if (Fs::exists($dir)) {
            throw new \RuntimeException("Release directory {$dir} already exists.");
        }
        $this->say("==> Creating release {$deployId} (" . substr($sha, 0, 7) . ')');
        $git = new Git((new Credentials($this->ud))->tokenFor($site->id));
        $git->cloneRelease($this->ud->repoDir($site->id), $dir, $sha, $this->env->publicRepoUrl($site->repo));
        if ($site->webDir === '.') {
            // The repository root is web-accessible: do not expose .git.
            Fs::remove($dir . '/.git');
        }
        file_put_contents($dir . '/REVISION', $sha . "\n");
        $this->linkShared($site, $releases, $dir);
        $this->say("==> Release ready in {$dir}");
    }

    private function linkShared(Site $site, Releases $releases, string $dir): void
    {
        foreach ($site->sharedPaths as $p) {
            if (!Fs::isSafeRelative($p)) {
                continue;
            }
            $shared = $releases->sharedDir() . '/' . $p;
            $inRelease = $dir . '/' . $p;
            if (!Fs::exists($shared)) {
                Fs::ensureDir(dirname($shared), 0755);
                if (Fs::exists($inRelease) && !is_link($inRelease)) {
                    if (!@rename($inRelease, $shared)) {
                        throw new \RuntimeException("Could not move {$p} to shared/");
                    }
                    $this->say("    shared/{$p} seeded from the repository");
                } elseif (is_file($inRelease . '.example')) {
                    copy($inRelease . '.example', $shared);
                    @chmod($shared, 0640);
                    $this->say("    shared/{$p} created from {$p}.example — review it in DirectAdmin");
                } elseif (str_contains(basename($p), '.')) {
                    touch($shared);
                    @chmod($shared, 0640);
                    $this->say("    shared/{$p} created (empty)");
                } else {
                    Fs::ensureDir($shared, 0755);
                    $this->say("    shared/{$p}/ created (empty)");
                }
            }
            Fs::remove($inRelease);
            Fs::ensureDir(dirname($inRelease), 0755);
            // Relative link: releases/<id>/<p> -> ../../shared/<p>
            $target = str_repeat('../', substr_count($p, '/') + 2) . 'shared/' . $p;
            if (!@symlink($target, $inRelease)) {
                throw new \RuntimeException("Could not link {$p} to shared/{$p}");
            }
        }
        if ($site->sharedPaths !== []) {
            $this->say('==> Linked shared paths: ' . implode(', ', $site->sharedPaths));
        }
    }

    private function activateRelease(Site $site, string $deployId): void
    {
        $releases = new Releases($site->basePath);
        $previous = $releases->activate($deployId);
        file_put_contents($this->ud->activationMarker($site->id, $deployId), (string) $previous);
        $this->say("==> Activated release {$deployId}" . ($previous !== null ? " (previous: {$previous})" : ''));
        if (!is_dir($site->docrootPath())) {
            $this->say("WARNING: {$site->docrootPath()} does not exist. Check the web directory setting of this site.");
        }
        $settings = Settings::load($this->env);
        $keep = min($site->keepReleases, $settings->int('max_keep_releases'));
        $removed = $releases->cleanup($keep, array_values(array_filter([$deployId, $previous])));
        if ($removed !== []) {
            $this->say('==> Removed old releases: ' . implode(', ', $removed));
        }
    }

    /**
     * $REQUIRE_NODE(): stop the deployment unless Node.js (Toolchain::MIN_NODE_MAJOR or newer)
     * and npm are on the script's PATH, which includes whatever the script added before (for
     * example by sourcing nvm.sh).
     */
    private function requireNode(): void
    {
        $path = (string) getenv('PATH');
        $found = Toolchain::node($path, null, true);
        $min = Toolchain::MIN_NODE_MAJOR;
        $missing = array_keys(array_filter($found, static fn (?array $tool): bool => $tool === null));
        if ($missing !== []) {
            throw new \RuntimeException(
                "Node.js {$min} or newer and npm are needed to build this release, but " . implode(' and ', $missing)
                . (count($missing) === 1 ? ' was' : ' were') . " not found on PATH ({$path}). " . $this->nodeAdvice(),
            );
        }
        $node = $found['node'];
        if ($node['version'] === null) {
            throw new \RuntimeException("{$node['path']} --version failed; the Node.js installation looks broken.");
        }
        if (!$node['supported']) {
            throw new \RuntimeException(
                "Node.js {$min} or newer is needed to build this release, but {$node['path']} is version {$node['version']}. " . $this->nodeAdvice(),
            );
        }
        $real = realpath($node['path']) ?: $node['path']; // through the admin's node-bin links
        $this->say("==> Node.js {$node['version']} ({$real}), npm " . ($found['npm']['version'] ?? 'version unknown'));
    }

    /** Where to get a suitable Node.js: the admin's broken override, an install in the home directory, or in general. */
    private function nodeAdvice(): string
    {
        $admin = Toolchain::configured(Settings::load($this->env)->all())['node'];
        if ($admin !== null && !(is_file($admin) && is_executable($admin))) {
            return "The server administrator set Node.js to {$admin}, but it cannot be run. Ask your hosting provider to correct it under Admin → Laravel Github deploy → Settings.";
        }
        $env = getenv();
        $hint = Toolchain::hint($this->ud->home, is_array($env) ? $env : []);
        return $hint !== null ? Toolchain::hintText($hint) : Toolchain::installAdvice();
    }

    private function restartQueues(Site $site): void
    {
        $current = $site->currentPath();
        if (!is_file($current . '/artisan')) {
            $this->say('==> No artisan file found; skipping queue restart');
            return;
        }
        $php = (string) getenv('DEPLOY_PHP');
        if ($php === '') {
            $php = Php::binary($this->env, $site->php);
        }
        $this->say('==> Restarting queues');
        [$code] = Proc::run([$php, 'artisan', 'queue:restart'], $current, null, fn (string $l) => $this->say($l));
        if ($code !== 0) {
            throw new \RuntimeException("php artisan queue:restart failed with exit code {$code}");
        }
    }
}
