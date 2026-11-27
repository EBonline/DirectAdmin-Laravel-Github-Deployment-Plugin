<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Runs one deployment (or rollback) for a site. Always runs as the site's owner.
 *
 * Flow: fetch the repository cache → resolve the commit → run the user's deploy
 * script, whose $CREATE_RELEASE() / $ACTIVATE_RELEASE() macros call bin/helper.php.
 * If the script fails before activation the new release is removed and `current`
 * is never touched.
 */
final class Deployer
{
    public function __construct(
        private Env $env,
        private UserData $ud,
        private Settings $settings,
    ) {
    }

    /**
     * @param array $target see GitHub (id, type, ref, sha, label, message, author, url)
     * @return array{status:string, activated:bool, release:string, error:?string}
     */
    public function deploy(Site $site, array $target, string $trigger): array
    {
        $releases = new Releases($site->basePath);
        try {
            $releases->ensureLayout();
        } catch (\Throwable $e) {
            $this->recordFailure($site, $trigger, (string) ($target['label'] ?? $target['id']), $e->getMessage());
            return ['status' => 'failed', 'activated' => false, 'release' => '', 'error' => $e->getMessage()];
        }
        $deployId = $releases->newId(array_column(State::read($this->ud, $site->id)['history'], 'id'));
        $log = new DeployLog($this->ud->logFile($site->id, $deployId));
        $started = time();
        $marker = $this->ud->activationMarker($site->id, $deployId);
        $scriptFile = $this->ud->tmpDir() . '/' . $site->id . '-' . $deployId . '.sh';

        $entry = [
            'id' => $deployId,
            'type' => 'deploy',
            'release' => $deployId,
            'trigger' => $trigger,
            'environment' => $site->environment,
            'target_id' => (string) $target['id'],
            'ref' => (string) ($target['ref'] ?? ''),
            'label' => (string) ($target['label'] ?? $target['id']),
            'sha' => $target['sha'] ?? null,
            'message' => (string) ($target['message'] ?? ''),
            'author' => (string) ($target['author'] ?? ''),
            'url' => $target['url'] ?? null,
            'status' => 'running',
            'activated' => false,
            'error' => null,
            'started_at' => $started,
            'finished_at' => null,
        ];
        State::update($this->ud, $site->id, static function (array $s) use ($entry): array {
            array_unshift($s['history'], $entry);
            $s['running'] = ['id' => $entry['id'], 'started_at' => $entry['started_at']];
            return $s;
        });

        $status = 'failed';
        $error = null;
        $reason = null; // the script's own explanation (its last ERROR: line), already in the log
        $activated = false;
        try {
            $log->line("Deploying {$entry['label']} ({$site->sourceLabel()}) to {$site->hostname} [{$site->environment}]");
            $git = new Git((new Credentials($this->ud))->tokenFor($site->id));
            $mirror = $this->ud->repoDir($site->id);

            $log->line("Fetching {$site->repo}");
            $git->syncMirror($mirror, $this->env->cloneUrl($site->repo));
            $sha = $this->resolveSha($git, $mirror, $site, $target);
            $info = $git->commitInfo($mirror, $sha);
            $entry['sha'] = $sha;
            if ($entry['message'] === '') {
                $entry['message'] = $info['message'];
            }
            if ($entry['author'] === '') {
                $entry['author'] = $info['author'];
            }
            $log->line('Commit ' . substr($sha, 0, 7) . ': ' . $info['message'] . ' (' . $info['author'] . ')');
            $this->patchEntry($site->id, $deployId, [
                'sha' => $sha,
                'message' => $entry['message'],
                'commit_message' => $info['message'],
                'author' => $entry['author'],
            ]);

            Fs::writeAtomic($scriptFile, ScriptBuilder::build($site->script), 0700);
            $timeout = $this->settings->int('deploy_timeout') * 60;
            $vars = $this->scriptEnv($site, $target, $deployId, $sha, $info, $trigger);

            $admin = array_filter(Toolchain::configured($this->settings->all()));
            if ($admin !== []) {
                $log->line('Set by the administrator: ' . implode(', ', array_map(
                    static fn (string $name, string $path): string => "{$name} → {$path}",
                    array_keys($admin),
                    $admin,
                )));
            }
            $log->line(Toolchain::summary($vars['DEPLOY_PHP'], Php::composer($this->env), $vars, $site->basePath));
            $log->line('Running deploy script');
            $lastError = null;
            [$code] = Proc::run(
                ['timeout', '--kill-after=30', (string) $timeout, '/bin/bash', $scriptFile],
                $site->basePath,
                $vars,
                static function (string $line) use ($log, &$lastError): void {
                    $log->line($line);
                    if (str_starts_with($line, 'ERROR: ')) {
                        $lastError = substr($line, 7);
                    }
                },
                0,
            );
            $activated = is_file($marker);
            if ($code === 0 && $activated) {
                $status = 'success';
            } elseif ($code === 0) {
                $error = 'The deploy script finished without running ' . ScriptBuilder::ACTIVATE . '.';
            } elseif ($code === 124 || $code === 137) {
                $error = 'The deploy script timed out after ' . $this->settings->int('deploy_timeout') . ' minutes.';
            } else {
                $error = "The deploy script failed with exit code {$code}"
                    . ($code === 127 && $lastError === null ? ' (a command was not found).' : '.');
                // The helper's macros (and well-behaved scripts) explain failures in an ERROR: line.
                $reason = $lastError !== null && $lastError !== '' ? gd_trim($lastError, 500) : null;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $activated = is_file($marker);
        }

        if ($error !== null) {
            $log->line('ERROR: ' . $error);
            if ($reason !== null) {
                // History and notifications show why, not just the exit code.
                $error = substr($error, 0, -1) . ': ' . $reason;
            }
        }
        if (!$activated) {
            try {
                if (Fs::exists($releases->path($deployId))) {
                    $releases->remove($deployId);
                    $log->line("Removed unfinished release {$deployId}; the site still runs the previous release.");
                }
            } catch (\Throwable $e) {
                $log->line('WARNING: could not remove release: ' . $e->getMessage());
            }
        } elseif ($status !== 'success') {
            $status = 'failed_after_activation';
            $log->line('The new release was already activated and stays live.');
        }

        $finished = time();
        $log->line('Finished: ' . $status . ' in ' . gd_duration($finished - $started));
        $log->close();
        @unlink($marker);
        @unlink($scriptFile);

        $this->finishEntry($site->id, $deployId, [
            'status' => $status,
            'activated' => $activated,
            'error' => $error,
            'finished_at' => $finished,
        ]);

        return ['status' => $status, 'activated' => $activated, 'release' => $deployId, 'error' => $error];
    }

    /** Point `current` back at a retained release. */
    public function rollback(Site $site, string $releaseId): array
    {
        $releases = new Releases($site->basePath);
        $entryId = 'rollback-' . date('YmdHis');
        $log = new DeployLog($this->ud->logFile($site->id, $entryId));
        $state = State::read($this->ud, $site->id);
        $source = State::entryForRelease($state, $releaseId) ?? [];
        $now = time();
        $entry = [
            'id' => $entryId,
            'type' => 'rollback',
            'release' => $releaseId,
            'trigger' => 'manual',
            'environment' => $site->environment,
            'target_id' => (string) ($source['target_id'] ?? ''),
            'ref' => (string) ($source['ref'] ?? ''),
            'label' => (string) ($source['label'] ?? $releaseId),
            'sha' => $source['sha'] ?? null,
            'message' => 'Rollback to release ' . $releaseId,
            'author' => '',
            'url' => $source['url'] ?? null,
            'status' => 'failed',
            'activated' => false,
            'error' => null,
            'started_at' => $now,
            'finished_at' => $now,
        ];
        try {
            if ($releases->currentId() === $releaseId) {
                throw new \RuntimeException("Release {$releaseId} is already active.");
            }
            if (!is_dir($releases->path($releaseId))) {
                throw new \RuntimeException("Release {$releaseId} no longer exists.");
            }
            if ($source !== [] && empty($source['activated'])) {
                throw new \RuntimeException("Release {$releaseId} never finished deploying and cannot be activated.");
            }
            $previous = $releases->activate($releaseId);
            $log->line("Rolled back from " . ($previous ?? 'nothing') . " to {$releaseId} ({$entry['label']})");
            $entry['status'] = 'success';
            $entry['activated'] = true;
        } catch (\Throwable $e) {
            $entry['error'] = $e->getMessage();
            $log->line('ERROR: ' . $e->getMessage());
        }
        $log->close();
        $this->pushEntry($site->id, $entry);
        return ['status' => $entry['status'], 'activated' => $entry['activated'], 'release' => $releaseId, 'error' => $entry['error']];
    }

    /** Record a deployment that could not even start (e.g. GitHub unreachable). */
    public function recordFailure(Site $site, string $trigger, string $label, string $error): void
    {
        $now = time();
        $id = 'failed-' . date('YmdHis');
        $log = new DeployLog($this->ud->logFile($site->id, $id));
        $log->line('ERROR: ' . $error);
        $log->close();
        $this->pushEntry($site->id, [
            'id' => $id,
            'type' => 'deploy',
            'release' => null,
            'trigger' => $trigger,
            'environment' => $site->environment,
            'target_id' => '',
            'ref' => '',
            'label' => $label,
            'sha' => null,
            'message' => '',
            'author' => '',
            'url' => null,
            'status' => 'failed',
            'activated' => false,
            'error' => $error,
            'started_at' => $now,
            'finished_at' => $now,
        ]);
    }

    private function resolveSha(Git $git, string $mirror, Site $site, array $target): string
    {
        if (!empty($target['sha'])) {
            $sha = $git->resolve($mirror, (string) $target['sha']);
            $what = 'commit ' . $target['sha'];
        } elseif ($target['type'] === 'release') {
            $sha = $git->resolve($mirror, 'refs/tags/' . $target['ref']);
            $what = 'tag ' . $target['ref'];
        } else {
            $sha = $git->resolve($mirror, 'refs/heads/' . $target['ref']);
            $what = 'branch ' . $target['ref'];
        }
        if ($sha === null) {
            throw new \RuntimeException("Could not find {$what} in {$site->repo}.");
        }
        return $sha;
    }

    /** @return array<string,string> */
    private function scriptEnv(Site $site, array $target, string $deployId, string $sha, array $info, string $trigger): array
    {
        $php = Php::binary($this->env, $site->php);
        $composer = Php::composer($this->env);
        $home = $this->ud->home;
        $nodeShims = Toolchain::shims($this->ud->nodeBinDir(), Toolchain::configured($this->settings->all()));
        $isRelease = $target['type'] === 'release';
        $message = (string) ($target['type'] === 'commit' && ($target['message'] ?? '') !== '' ? $target['message'] : $info['message']);
        $author = (string) (($target['author'] ?? '') !== '' ? $target['author'] : $info['author']);

        $vars = [
            'HOME' => $home,
            'USER' => $this->ud->user,
            'LOGNAME' => $this->ud->user,
            'PATH' => Toolchain::deployPath($php, $home, $nodeShims),
            'LANG' => 'C.UTF-8',
            'SHELL' => '/bin/bash',
            'TERM' => 'dumb',
            'COMPOSER_NO_INTERACTION' => '1',

            'DEPLOY_SITE_ROOT' => $site->basePath,
            'DEPLOY_SITE_PATH' => $site->currentPath(),
            'DEPLOY_RELEASE_DIRECTORY' => $site->basePath . '/releases/' . $deployId,
            'DEPLOY_SHARED_DIRECTORY' => $site->sharedDir(),
            'DEPLOY_ID' => $deployId,
            'DEPLOY_ENVIRONMENT' => $site->environment,
            'DEPLOY_BRANCH' => $isRelease ? '' : (string) $target['ref'],
            'DEPLOY_TAG' => $isRelease ? (string) $target['ref'] : '',
            'DEPLOY_COMMIT' => $sha,
            'DEPLOY_COMMIT_MESSAGE' => $message,
            'DEPLOY_AUTHOR' => $author,
            'DEPLOY_MANUAL' => $trigger === 'manual' ? '1' : '0',
            'DEPLOY_PHP' => $php,
            'DEPLOY_COMPOSER' => $composer !== null ? $php . ' ' . $composer : 'composer',

            'GIT_DEPLOY_PHP_CLI' => $this->env->phpCli(),
            'GIT_DEPLOY_PHP_INI' => $this->env->phpIni(),
            'GIT_DEPLOY_HELPER' => $this->env->pluginDir . '/bin/helper.php',
            'GIT_DEPLOY_SITE' => $site->id,
            'GIT_DEPLOY_ID' => $deployId,
            'GIT_DEPLOY_SHA' => $sha,
        ];
        // Forge-compatible aliases so existing Forge deploy scripts work unchanged.
        $vars += [
            'FORGE_SITE_ROOT' => $vars['DEPLOY_SITE_ROOT'],
            'FORGE_SITE_PATH' => $vars['DEPLOY_SITE_PATH'],
            'FORGE_RELEASE_DIRECTORY' => $vars['DEPLOY_RELEASE_DIRECTORY'],
            'FORGE_SITE_BRANCH' => $vars['DEPLOY_BRANCH'],
            'FORGE_PHP' => $vars['DEPLOY_PHP'],
            'FORGE_COMPOSER' => $vars['DEPLOY_COMPOSER'],
            'FORGE_DEPLOY_COMMIT' => $vars['DEPLOY_COMMIT'],
            'FORGE_DEPLOY_MESSAGE' => $vars['DEPLOY_COMMIT_MESSAGE'],
            'FORGE_DEPLOY_AUTHOR' => $vars['DEPLOY_AUTHOR'],
            'FORGE_MANUAL_DEPLOY' => $vars['DEPLOY_MANUAL'],
            'FORGE_SITE_USER' => $this->ud->user,
        ];
        return $vars + $this->env->testVars();
    }

    private function patchEntry(string $siteId, string $id, array $fields): void
    {
        State::update($this->ud, $siteId, static function (array $s) use ($id, $fields): array {
            foreach ($s['history'] as $i => $h) {
                if (($h['id'] ?? null) === $id) {
                    $s['history'][$i] = array_replace($h, $fields);
                    break;
                }
            }
            return $s;
        });
    }

    private function finishEntry(string $siteId, string $id, array $fields): void
    {
        $this->patchEntry($siteId, $id, $fields);
        State::update($this->ud, $siteId, function (array $s) use ($siteId): array {
            $s['running'] = null;
            return $this->prune($siteId, $s);
        });
    }

    private function pushEntry(string $siteId, array $entry): void
    {
        State::update($this->ud, $siteId, function (array $s) use ($siteId, $entry): array {
            array_unshift($s['history'], $entry);
            return $this->prune($siteId, $s);
        });
    }

    /** Cap history and delete the logs of dropped entries. */
    private function prune(string $siteId, array $s): array
    {
        if (count($s['history']) <= State::HISTORY_LIMIT) {
            return $s;
        }
        foreach (array_slice($s['history'], State::HISTORY_LIMIT) as $old) {
            try {
                @unlink($this->ud->logFile($siteId, (string) ($old['id'] ?? '')));
            } catch (\InvalidArgumentException) {
            }
        }
        $s['history'] = array_slice($s['history'], 0, State::HISTORY_LIMIT);
        return $s;
    }
}
