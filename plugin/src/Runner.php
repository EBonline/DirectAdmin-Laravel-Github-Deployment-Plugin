<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Per-user work loop, started every minute by the root worker (as the user):
 * handle queued manual actions, poll GitHub when a site's interval has passed, and
 * deploy when the target changed. Prints one JSON summary for the root worker.
 */
final class Runner
{
    public function __construct(
        private Env $env,
        private UserData $ud,
        private Settings $settings,
    ) {
    }

    /**
     * `nvm`: the Node.js this user installed with nvm, which deploy scripts find on PATH
     * (Toolchain::nvmNode()); the admin account cannot look in the user's home itself.
     *
     * @return array{user:string, activated:list<array>, sites:list<array>, nvm:?array{version:string,npm:bool}}
     */
    public function run(bool $forcePoll = false, ?string $onlySite = null): array
    {
        $result = ['user' => $this->ud->user, 'activated' => [], 'sites' => [], 'nvm' => Toolchain::nvmNode($this->ud->home)];
        $this->ud->ensure();
        foreach ((new Sites($this->ud))->all() as $site) {
            if ($onlySite !== null && $site->id !== $onlySite) {
                continue;
            }
            $lock = @fopen($this->ud->lockFile($site->id), 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    $this->recoverInterrupted($site);
                    if ($this->processSite($site, $forcePoll)) {
                        $result['activated'][] = ['site' => $site->id, 'php' => $site->php];
                    }
                } catch (\Throwable $e) {
                    State::update($this->ud, $site->id, static function (array $s) use ($e): array {
                        $s['poll_error'] = $e->getMessage();
                        return $s;
                    });
                } finally {
                    flock($lock, LOCK_UN);
                }
            }
            if ($lock !== false) {
                fclose($lock);
            }
            $result['sites'][] = $this->summary($site);
        }
        return $result;
    }

    /** @return bool whether a release was activated */
    private function processSite(Site $site, bool $forcePoll): bool
    {
        $request = $this->takeQueued($site);
        if ($request !== null) {
            return $this->handleRequest($site, $request);
        }

        $state = State::read($this->ud, $site->id);
        $interval = $this->settings->int('poll_interval') * 60;
        if (!$forcePoll && time() - (int) $state['last_poll'] < $interval - 10) {
            return false;
        }
        $remote = $this->poll($site);
        if ($remote === null || !$site->autoDeploy) {
            return false;
        }
        if ($remote['id'] === State::read($this->ud, $site->id)['last_handled']) {
            return false;
        }
        $this->markHandled($site, $remote['id']);
        return $this->deployer()->deploy($site, $remote, 'auto')['activated'];
    }

    /** Ask GitHub for the newest target; stores it in state. Returns null when unknown. */
    public function poll(Site $site): ?array
    {
        $state = State::read($this->ud, $site->id);
        $gh = new GitHub($this->env->apiBase(), (new Credentials($this->ud))->tokenFor($site->id));
        // An ETag is only valid for the exact source it was issued for (repo/branch may change).
        $key = $site->repo . '|' . ($site->isProduction() ? 'release' : 'branch:' . $site->branch);
        $etag = ($state['etag_key'] ?? null) === $key ? $state['etag'] : null;
        try {
            $res = $site->isProduction()
                ? $gh->latestRelease($site->repo, $etag)
                : $gh->branchHead($site->repo, $site->branch, $etag);
        } catch (GitHubException $e) {
            State::update($this->ud, $site->id, static function (array $s) use ($e): array {
                $s['last_poll'] = time();
                $s['poll_error'] = $e->getMessage();
                return $s;
            });
            return null;
        }
        $state = State::update($this->ud, $site->id, static function (array $s) use ($res, $key): array {
            $s['last_poll'] = time();
            $s['poll_error'] = null;
            if ($res['status'] === 200) {
                $s['remote'] = $res['target'];
                $s['etag'] = $res['etag'];
                $s['etag_key'] = $key;
            } elseif ($res['status'] === 404) {
                $s['remote'] = null;
                $s['etag'] = null;
                $s['etag_key'] = null;
            }
            return $s;
        });
        return is_array($state['remote']) ? $state['remote'] : null;
    }

    private function handleRequest(Site $site, array $request): bool
    {
        $deployer = $this->deployer();
        switch ($request['action'] ?? '') {
            case 'deploy':
                // Fresh lookup without the stored ETag so a stale cache cannot hide a change.
                State::update($this->ud, $site->id, static function (array $s): array {
                    $s['etag'] = null;
                    return $s;
                });
                $remote = $this->poll($site);
                if ($remote === null) {
                    $state = State::read($this->ud, $site->id);
                    $reason = $state['poll_error'] ?? ($site->isProduction() ? 'This repository has no published release yet.' : 'Could not find the branch head.');
                    $deployer->recordFailure($site, 'manual', $site->isProduction() ? 'latest release' : $site->branch, (string) $reason);
                    return false;
                }
                $this->markHandled($site, $remote['id']);
                return $deployer->deploy($site, $remote, 'manual')['activated'];

            case 'deploy_ref':
                $ref = (string) ($request['ref'] ?? '');
                if ($site->isProduction()) {
                    if (!Validate::tag($ref)) {
                        $deployer->recordFailure($site, 'manual', $ref, 'Invalid tag.');
                        return false;
                    }
                    $target = ['id' => $ref, 'type' => 'release', 'ref' => $ref, 'sha' => null, 'label' => $ref,
                        'message' => (string) ($request['message'] ?? ''), 'author' => '', 'url' => null];
                } else {
                    if (!Validate::sha($ref)) {
                        $deployer->recordFailure($site, 'manual', $ref, 'Invalid commit sha.');
                        return false;
                    }
                    $target = ['id' => $ref, 'type' => 'commit', 'ref' => $site->branch, 'sha' => $ref,
                        'label' => substr($ref, 0, 7), 'message' => (string) ($request['message'] ?? ''), 'author' => '', 'url' => null];
                }
                return $deployer->deploy($site, $target, 'manual')['activated'];

            case 'rollback':
                return $deployer->rollback($site, (string) ($request['release'] ?? ''))['activated'];
        }
        return false;
    }

    private function deployer(): Deployer
    {
        return new Deployer($this->env, $this->ud, $this->settings);
    }

    private function markHandled(Site $site, string $targetId): void
    {
        State::update($this->ud, $site->id, static function (array $s) use ($targetId): array {
            $s['last_handled'] = $targetId;
            return $s;
        });
    }

    /** Claim the queued request (if any) for this site. */
    private function takeQueued(Site $site): ?array
    {
        $file = $this->ud->queueFile($site->id);
        if (!is_file($file)) {
            return null;
        }
        $claimed = $file . '.' . getmypid();
        if (!@rename($file, $claimed)) {
            return null;
        }
        $request = Store::read($claimed, []);
        @unlink($claimed);
        return $request !== [] ? $request : null;
    }

    /**
     * We hold the site lock, so a history entry still marked "running" was interrupted
     * (server reboot, killed process). Mark it failed and remove its unfinished release.
     */
    private function recoverInterrupted(Site $site): void
    {
        $state = State::read($this->ud, $site->id);
        if ($state['running'] === null) {
            return;
        }
        $releases = new Releases($site->basePath);
        $current = $releases->currentId();
        State::update($this->ud, $site->id, static function (array $s) use ($releases, $current): array {
            foreach ($s['history'] as $i => $h) {
                if (($h['status'] ?? '') !== 'running') {
                    continue;
                }
                $activated = ($h['release'] ?? null) !== null && $h['release'] === $current;
                $s['history'][$i]['status'] = $activated ? 'failed_after_activation' : 'failed';
                $s['history'][$i]['activated'] = $activated;
                $s['history'][$i]['error'] = 'The deployment was interrupted.';
                $s['history'][$i]['finished_at'] = time();
                if (!$activated && is_string($h['release'] ?? null)) {
                    try {
                        $releases->remove($h['release']);
                    } catch (\Throwable) {
                    }
                }
            }
            $s['running'] = null;
            return $s;
        });
    }

    /** Compact, non-sensitive status for the admin overview. */
    public function summary(Site $site): array
    {
        $state = State::read($this->ud, $site->id);
        $currentId = (new Releases($site->basePath))->currentId();
        $current = State::entryForRelease($state, $currentId);
        $last = $state['history'][0] ?? null;
        return [
            'id' => $site->id,
            'hostname' => $site->hostname,
            'environment' => $site->environment,
            'repo' => $site->repo,
            'source' => $site->sourceLabel(),
            'auto_deploy' => $site->autoDeploy,
            'current_release' => $currentId,
            'current_label' => $current['label'] ?? null,
            'current_sha' => $current['sha'] ?? null,
            'last_status' => $last['status'] ?? null,
            'last_at' => $last['finished_at'] ?? $last['started_at'] ?? null,
            'last_error' => $last['error'] ?? null,
            'last_poll' => $state['last_poll'] ?: null,
            'poll_error' => $state['poll_error'],
        ];
    }
}
