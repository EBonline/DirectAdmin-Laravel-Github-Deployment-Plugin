<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * JSON API of the user-level interface (/CMD_PLUGINS/<plugin>/api.raw). DirectAdmin runs it
 * as the logged-in user, so everything here reads and writes that user's own files only.
 */
final class UserApi extends JsonApi
{
    public const ENV_MAX = 65536;
    private const LOG_TAIL = 512 * 1024;
    private const LOG_CHUNK = 256 * 1024;

    private Sites $sites;
    private Credentials $creds;

    public function __construct(
        private Env $env,
        private UserData $ud,
        private Settings $settings,
        Request $req,
        Csrf $csrf,
    ) {
        parent::__construct($req, $csrf);
        $this->sites = new Sites($ud);
        $this->creds = new Credentials($ud);
    }

    protected function reads(): array
    {
        return [
            'overview' => fn (): array => $this->overview(),
            'site' => fn (): array => $this->siteDetail($this->site($this->req->query('site'))),
            'site_form' => fn (): array => $this->siteForm(),
            'refs' => fn (): array => $this->refs(),
            'env' => fn (): array => $this->envFile(),
            'log' => fn (): array => $this->log(),
            'settings' => fn (): array => $this->settingsData(),
        ];
    }

    protected function writes(): array
    {
        return [
            'save_site' => fn (array $p): array => $this->saveSite($p),
            'check_repo' => fn (array $p): array => $this->checkRepo($p),
            'delete_site' => fn (array $p): array => $this->deleteSite($p),
            'set_auto' => fn (array $p): array => $this->setAuto($p),
            'deploy' => fn (array $p): array => $this->enqueue($this->site(self::str($p, 'site')), ['action' => 'deploy']),
            'deploy_ref' => fn (array $p): array => $this->deployRef($p),
            'rollback' => fn (array $p): array => $this->rollback($p),
            'cancel_queue' => fn (array $p): array => $this->cancelQueue($p),
            'check_docroot' => fn (array $p): array => $this->checkDocroot($p),
            'save_env' => fn (array $p): array => $this->saveEnv($p),
            'save_token' => fn (array $p): array => $this->saveToken($p),
            'remove_token' => fn (array $p): array => $this->removeToken(),
        ];
    }

    // ---------------------------------------------------------------- reads

    private function overview(): array
    {
        $rows = [];
        foreach ($this->sites->all() as $site) {
            $rows[] = $this->status($site);
        }
        return [
            'sites' => $rows,
            'has_token' => $this->creds->defaultToken() !== null,
            'poll_interval' => $this->settings->int('poll_interval'),
        ];
    }

    private function siteDetail(Site $site): array
    {
        $state = State::read($this->ud, $site->id);
        $releases = new Releases($site->basePath);
        $currentId = $releases->currentId();
        $list = [];
        foreach (array_reverse($releases->all()) as $id) {
            $entry = State::entryForRelease($state, $id);
            $list[] = [
                'id' => $id,
                'entry' => $entry,
                'current' => $id === $currentId,
                'can_activate' => $entry === null || !empty($entry['activated']),
            ];
        }
        return $this->status($site, $state) + [
            'history' => $state['history'],
            'releases' => $list,
            'poll_interval' => $this->settings->int('poll_interval'),
            'deploy_timeout' => $this->settings->int('deploy_timeout'),
            'env_shared' => in_array('.env', $site->sharedPaths, true),
            'env_exists' => is_file($site->sharedDir() . '/.env'),
            'has_token' => $this->creds->tokenFor($site->id) !== null,
        ];
    }

    private function siteForm(): array
    {
        $id = $this->req->query('site');
        $existing = $id !== '' ? $this->site($id) : null;
        if ($existing !== null) {
            $site = $existing;
        } else {
            $site = new Site();
            $site->keepReleases = $this->settings->int('keep_releases');
            $site->script = ScriptBuilder::defaultScript($this->env);
            $site->hostname = strtolower($this->req->query('hostname'));
        }
        $siteToken = $existing !== null ? $this->creds->siteToken($existing->id) : null;
        return [
            'site' => $this->siteDto($site, true),
            'is_new' => $existing === null,
            'domains' => Domains::forHome($this->ud->home),
            'php_versions' => $this->phpVersions(),
            'max_keep' => $this->settings->int('max_keep_releases'),
            'max_shared' => SiteForm::MAX_SHARED,
            'site_token' => $siteToken !== null ? Credentials::mask($siteToken) : null,
            'has_default_token' => $this->creds->defaultToken() !== null,
            'home' => $this->ud->home,
            'default_script' => ScriptBuilder::defaultScript($this->env),
            'outdated_script' => $existing !== null && ScriptBuilder::isPreviousDefault($existing->script),
            'node' => $this->nodeStatus($site),
        ];
    }

    /** Node.js and npm as a deploy script of this site would find them (before the script changes PATH). */
    private function nodeStatus(Site $site): array
    {
        $admin = Toolchain::configured($this->settings->all());
        try {
            $shims = Toolchain::shims($this->ud->nodeBinDir(), $admin);
        } catch (\Throwable) {
            $shims = null; // the next deployment reports it
        }
        $path = Toolchain::deployPath(Php::binary($this->env, $site->php), $this->ud->home, $shims);
        return Toolchain::node($path, ['PATH' => $path, 'HOME' => $this->ud->home]) + [
            'path' => $path,
            'hint' => Toolchain::hint($this->ud->home),
            'min_major' => Toolchain::MIN_NODE_MAJOR,
            'admin' => [
                'node' => $admin['node'],
                'npm' => $admin['npm'],
                'usable' => $admin['node'] === null || (is_file($admin['node']) && is_executable($admin['node'])),
            ],
        ];
    }

    private function refs(): array
    {
        $site = $this->site($this->req->query('site'));
        $token = $this->creds->tokenFor($site->id);
        if ($token === null) {
            throw new ApiError(400, 'no_token', 'Add a GitHub token under Settings first.');
        }
        $gh = new GitHub($this->env->apiBase(), $token);
        $items = $site->isProduction() ? $gh->releases($site->repo) : $gh->commits($site->repo, $site->branch);
        $state = State::read($this->ud, $site->id);
        $current = State::entryForRelease($state, (new Releases($site->basePath))->currentId());
        return [
            'site' => $this->siteDto($site),
            'kind' => $site->isProduction() ? 'release' : 'commit',
            'items' => $items,
            'current_target' => $current['target_id'] ?? null,
        ];
    }

    private function envFile(): array
    {
        $site = $this->site($this->req->query('site'));
        $file = $site->sharedDir() . '/.env';
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        return [
            'site' => $this->siteDto($site),
            'path' => $file,
            'content' => $content,
            'exists' => is_file($file),
            'valid_utf8' => preg_match('//u', $content) === 1,
            'shared' => in_array('.env', $site->sharedPaths, true),
            'max_bytes' => self::ENV_MAX,
        ];
    }

    /**
     * A deployment log. Without `offset`, the last 512 KB (starting at a line boundary);
     * with `offset`, what was appended since (at most 256 KB, cut at a line boundary).
     */
    private function log(): array
    {
        $site = $this->site($this->req->query('site'));
        $id = $this->req->query('id');
        $file = $this->ud->logFile($site->id, $id);
        $state = State::read($this->ud, $site->id);
        $entry = null;
        foreach ($state['history'] as $h) {
            if (($h['id'] ?? null) === $id) {
                $entry = $h;
                break;
            }
        }
        clearstatcache(true, $file);
        $size = is_file($file) ? (int) filesize($file) : 0;
        $offsetParam = $this->req->query('offset');
        $initial = $offsetParam === '';
        $reset = false;
        if ($initial) {
            $start = max(0, $size - self::LOG_TAIL);
        } else {
            $start = max(0, (int) $offsetParam);
            if ($start > $size) {
                $start = 0;
                $reset = true;
            }
        }
        $len = $initial ? $size - $start : min(self::LOG_CHUNK, $size - $start);
        $chunk = $len > 0 ? (string) file_get_contents($file, false, null, $start, $len) : '';
        $truncated = $initial && $start > 0;
        if ($truncated && ($nl = strpos($chunk, "\n")) !== false) {
            $start += $nl + 1;
            $chunk = substr($chunk, $nl + 1);
        }
        $more = $start + strlen($chunk) < $size;
        if ($more && ($nl = strrpos($chunk, "\n")) !== false) {
            $chunk = substr($chunk, 0, $nl + 1);
        }
        return [
            'site' => ['id' => $site->id, 'hostname' => $site->hostname, 'environment' => $site->environment],
            'entry' => $entry,
            'chunk' => $chunk,
            'offset' => $start + strlen($chunk),
            'more' => $more,
            'truncated' => $truncated,
            'reset' => $reset,
            'running' => ($state['running']['id'] ?? null) === $id,
        ];
    }

    private function settingsData(): array
    {
        $token = $this->creds->defaultToken();
        return [
            'token' => $token !== null ? Credentials::mask($token) : null,
            'has_token' => $token !== null,
            'server' => [
                'poll_interval' => $this->settings->int('poll_interval'),
                'deploy_timeout' => $this->settings->int('deploy_timeout'),
                'max_keep_releases' => $this->settings->int('max_keep_releases'),
            ],
            'php_versions' => $this->phpVersions(),
            'composer' => Php::composer($this->env),
        ];
    }

    // --------------------------------------------------------------- writes

    private function saveSite(array $p): array
    {
        $id = self::str($p, 'site');
        $existing = $id !== '' ? $this->site($id) : null;
        [$site, $errors] = (new SiteForm($this->env, $this->ud, $this->settings, $this->sites))
            ->fromRequest($this->req->withPost($p), $existing);

        $tokenInput = self::str($p, 'site_token');
        $clearToken = !empty($p['clear_site_token']);
        if ($tokenInput !== '' && !Validate::token($tokenInput)) {
            $errors['site_token'] = 'This does not look like a GitHub token.';
        }
        if ($errors !== []) {
            throw ApiError::validation($errors);
        }
        $token = $tokenInput !== ''
            ? $tokenInput
            : ($existing === null || $clearToken ? $this->creds->defaultToken() : $this->creds->tokenFor($existing->id));
        $this->verifyAccess($site->repo, $site->isProduction(), $site->branch, $token);

        if ($existing === null) {
            $site->id = $this->sites->newId($site->hostname);
            $site->createdAt = time();
        }
        $this->sites->save($site);
        if ($tokenInput !== '') {
            $this->creds->setSite($site->id, $tokenInput);
        } elseif ($clearToken) {
            $this->creds->setSite($site->id, null);
        }
        $sourceChanged = $existing === null
            || $existing->repo !== $site->repo
            || $existing->environment !== $site->environment
            || (!$site->isProduction() && $existing->branch !== $site->branch);
        if ($sourceChanged) {
            State::update($this->ud, $site->id, static function (array $s): array {
                $s['last_poll'] = 0;
                $s['last_handled'] = null;
                $s['remote'] = null;
                $s['etag'] = null;
                return $s;
            });
        }
        $message = match (true) {
            $existing !== null => 'Site saved.',
            $site->autoDeploy => 'Site created. The first deployment starts within a minute.',
            default => 'Site created. Automatic deployments are off, so deploy it when you are ready.',
        };
        return ['site' => ['id' => $site->id], 'created' => $existing === null, 'message' => $message];
    }

    /** Live check while the user fills in the site form: can the token see the repository (and branch)? */
    private function checkRepo(array $p): array
    {
        $repo = Validate::repo(self::str($p, 'repo'));
        if ($repo === null) {
            throw ApiError::validation(['repo' => 'Use the form owner/repository (or paste the GitHub URL).']);
        }
        $production = self::str($p, 'environment') === 'production';
        $branch = self::str($p, 'branch') !== '' ? self::str($p, 'branch') : 'main';
        if (!$production && !Validate::branch($branch)) {
            throw ApiError::validation(['branch' => 'Enter a valid branch name.']);
        }
        $tokenInput = self::str($p, 'site_token');
        if ($tokenInput !== '' && !Validate::token($tokenInput)) {
            throw ApiError::validation(['site_token' => 'This does not look like a GitHub token.']);
        }
        $siteId = self::str($p, 'site');
        $token = $tokenInput !== '' ? $tokenInput : match (true) {
            $siteId !== '' && empty($p['clear_site_token']) && preg_match(UserData::ID_PATTERN, $siteId) === 1 => $this->creds->tokenFor($siteId),
            default => $this->creds->defaultToken(),
        };
        $info = $this->verifyAccess($repo, $production, $branch, $token);
        if ($production) {
            $latest = (new GitHub($this->env->apiBase(), $token))->latestRelease($repo)['target'];
            $info['latest_release'] = $latest;
            $message = $latest !== null
                ? "{$info['repo']} is accessible. Latest release: {$latest['label']}."
                : "{$info['repo']} is accessible. It has no published release yet; the first deployment starts when you publish one.";
        } else {
            $head = $info['branch_head'];
            $message = "{$info['repo']} is accessible. Branch {$branch} found (latest commit {$head['label']}"
                . ($head['message'] !== '' ? ': ' . gd_trim($head['message'], 60) : '') . ').';
        }
        return ['check' => $info, 'message' => $message];
    }

    private function deleteSite(array $p): array
    {
        $site = $this->site(self::str($p, 'site'));
        if (strtolower(self::str($p, 'confirm')) !== $site->hostname) {
            throw ApiError::validation(['confirm' => "Type {$site->hostname} to confirm."]);
        }
        if (State::read($this->ud, $site->id)['running'] !== null) {
            throw ApiError::busy('A deployment is running. Try again when it has finished.');
        }
        $this->sites->delete($site->id);
        $this->creds->setSite($site->id, null);
        foreach ([$this->ud->stateFile($site->id), $this->ud->stateFile($site->id) . '.lock', $this->ud->queueFile($site->id), $this->ud->lockFile($site->id)] as $f) {
            @unlink($f);
        }
        Fs::remove($this->ud->logDir($site->id));
        Fs::remove($this->ud->repoDir($site->id));
        return ['message' => "Site {$site->hostname} removed. Its files in {$site->basePath} were left untouched."];
    }

    private function setAuto(array $p): array
    {
        $site = $this->site(self::str($p, 'site'));
        $site->autoDeploy = array_key_exists('enabled', $p) ? (bool) $p['enabled'] : !$site->autoDeploy;
        $this->sites->save($site);
        return [
            'auto_deploy' => $site->autoDeploy,
            'message' => $site->autoDeploy
                ? "Automatic deployments enabled for {$site->hostname}."
                : "Automatic deployments paused for {$site->hostname}. GitHub is still checked, so you can see when something new is available.",
        ];
    }

    private function deployRef(array $p): array
    {
        $site = $this->site(self::str($p, 'site'));
        $ref = self::str($p, 'ref');
        $ref = $site->isProduction() ? $ref : strtolower($ref);
        $valid = $site->isProduction() ? Validate::tag($ref) : Validate::sha($ref);
        if (!$valid) {
            throw ApiError::validation(['ref' => $site->isProduction() ? 'Enter a valid tag name, for example v1.2.3.' : 'Enter a commit sha (7–40 hexadecimal characters).']);
        }
        return $this->enqueue($site, [
            'action' => 'deploy_ref',
            'ref' => $ref,
            'message' => substr(self::str($p, 'message'), 0, 300),
        ]);
    }

    private function rollback(array $p): array
    {
        $site = $this->site(self::str($p, 'site'));
        $release = self::str($p, 'release');
        $releases = new Releases($site->basePath);
        if (preg_match(Releases::ID_PATTERN, $release) !== 1 || !is_dir($releases->path($release))) {
            throw ApiError::notFound('That release no longer exists.');
        }
        if ($releases->currentId() === $release) {
            throw ApiError::busy("Release {$release} is already live.");
        }
        return $this->enqueue($site, ['action' => 'rollback', 'release' => $release]);
    }

    private function enqueue(Site $site, array $request): array
    {
        $file = $this->ud->queueFile($site->id);
        if (is_file($file)) {
            throw ApiError::busy('Another action is already waiting to start. Wait for it, or cancel it first.');
        }
        $request += ['requested_at' => time()];
        Store::write($file, $request);
        $what = $request['action'] === 'rollback' ? 'Rollback' : 'Deployment';
        return ['queued' => $request, 'message' => "{$what} queued. It starts within a minute."];
    }

    private function cancelQueue(array $p): array
    {
        $site = $this->site(self::str($p, 'site'));
        $file = $this->ud->queueFile($site->id);
        if (!is_file($file)) {
            return ['cancelled' => false, 'message' => 'Nothing was waiting anymore; the action may have started already.'];
        }
        @unlink($file);
        return ['cancelled' => true, 'message' => 'Queued action cancelled.'];
    }

    private function checkDocroot(array $p): array
    {
        $site = $this->site(self::str($p, 'site'));
        $result = Docroot::check($site);
        State::update($this->ud, $site->id, static function (array $s) use ($result): array {
            $s['docroot'] = $result;
            return $s;
        });
        return ['docroot' => $result, 'message' => $result['message']];
    }

    private function saveEnv(array $p): array
    {
        $site = $this->site(self::str($p, 'site'));
        $raw = $p['content'] ?? null;
        if (!is_string($raw)) {
            throw ApiError::validation(['content' => 'Nothing to save.']);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $raw);
        if (strlen($content) > self::ENV_MAX) {
            throw ApiError::validation(['content' => 'The file is too large (max 64 KB).']);
        }
        Fs::ensureDir($site->sharedDir(), 0755);
        Fs::writeAtomic($site->sharedDir() . '/.env', $content, 0640);
        return [
            'bytes' => strlen($content),
            'message' => 'Saved. Deploy again (or clear the config cache) for the changes to take effect.',
        ];
    }

    private function saveToken(array $p): array
    {
        $token = self::str($p, 'token');
        if (!Validate::token($token)) {
            throw ApiError::validation(['token' => 'This does not look like a GitHub token.']);
        }
        try {
            $r = (new GitHub($this->env->apiBase(), $token))->get('/rate_limit');
            if ($r['status'] !== 200) {
                throw new GitHubException('GitHub did not accept the token.');
            }
        } catch (GitHubException $e) {
            throw ApiError::validation(['token' => $e->getMessage()], $e->getMessage());
        }
        $this->creds->setDefault($token);
        return ['token' => Credentials::mask($token), 'has_token' => true, 'message' => 'GitHub token saved. GitHub accepted it.'];
    }

    private function removeToken(): array
    {
        $this->creds->setDefault(null);
        return ['token' => null, 'has_token' => false, 'message' => 'GitHub token removed.'];
    }

    // -------------------------------------------------------------- helpers

    private function site(string $id): Site
    {
        $site = preg_match(UserData::ID_PATTERN, $id) === 1 ? $this->sites->get($id) : null;
        if ($site === null) {
            throw ApiError::notFound('This site does not exist (anymore).');
        }
        return $site;
    }

    /**
     * Checks that $token can read the repository (and, for testing sites, the branch).
     * Failures become field errors for the site form.
     */
    private function verifyAccess(string $repo, bool $production, string $branch, ?string $token): array
    {
        if ($token === null) {
            throw ApiError::validation(['site_token' => 'Add a GitHub token under Settings, or enter one for this site.'], 'There is no GitHub token to check the repository with.');
        }
        try {
            $gh = new GitHub($this->env->apiBase(), $token);
            $info = $gh->repository($repo);
            $head = $production ? null : $gh->branchHead($repo, $branch)['target'];
        } catch (GitHubException $e) {
            $field = match (true) {
                $e->getCode() === 401 => 'site_token',
                !$production && str_starts_with($e->getMessage(), 'Branch ') => 'branch',
                default => 'repo',
            };
            throw ApiError::validation([$field => $e->getMessage()], $e->getMessage());
        }
        return [
            'repo' => (string) ($info['full_name'] ?? $repo),
            'private' => (bool) ($info['private'] ?? false),
            'default_branch' => isset($info['default_branch']) ? (string) $info['default_branch'] : null,
            'branch_head' => $head,
        ];
    }

    /** Everything the list and detail pages show about a site. */
    private function status(Site $site, ?array $state = null): array
    {
        $state ??= State::read($this->ud, $site->id);
        $currentId = (new Releases($site->basePath))->currentId();
        $current = State::entryForRelease($state, $currentId);
        $remote = is_array($state['remote']) ? $state['remote'] : null;
        $queued = Store::read($this->ud->queueFile($site->id), []);
        return [
            'site' => $this->siteDto($site),
            'current_id' => $currentId,
            'current' => $current,
            'last' => $state['history'][0] ?? null,
            'remote' => $remote,
            'queued' => $queued !== [] ? $queued : null,
            'running' => $state['running'],
            'update_available' => $remote !== null && ($current['target_id'] ?? null) !== $remote['id'],
            'docroot' => $state['docroot'],
            'last_poll' => $state['last_poll'] ?: null,
            'poll_error' => $state['poll_error'],
        ];
    }

    private function siteDto(Site $site, bool $withScript = false): array
    {
        $a = $site->toArray();
        if (!$withScript) {
            unset($a['script']);
        }
        return $a + [
            'source_label' => $site->sourceLabel(),
            'docroot_path' => $site->docrootPath(),
            'current_path' => $site->currentPath(),
        ];
    }

    /** @return list<array{key:string,label:string}> */
    private function phpVersions(): array
    {
        return array_map(
            static fn (string $k): array => ['key' => $k, 'label' => Php::label($k)],
            array_map('strval', array_keys(Php::versions($this->env))),
        );
    }
}
