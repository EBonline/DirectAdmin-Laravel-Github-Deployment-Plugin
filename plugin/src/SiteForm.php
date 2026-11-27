<?php

declare(strict_types=1);

namespace GitDeploy;

/** Builds and validates a Site from the edit form. */
final class SiteForm
{
    public const MAX_SHARED = 20;

    public function __construct(
        private Env $env,
        private UserData $ud,
        private Settings $settings,
        private Sites $sites,
    ) {
    }

    /** @return array{0:Site,1:array<string,string>} */
    public function fromRequest(Request $req, ?Site $existing): array
    {
        $site = $existing !== null ? clone $existing : new Site();
        $errors = [];
        $domains = Domains::forHome($this->ud->home);

        $host = strtolower(trim($req->input('hostname')));
        $site->hostname = $host;
        if (!Validate::hostname($host)) {
            $errors['hostname'] = 'Enter a valid domain name, for example test.example.com.';
        } elseif (Domains::owner($host, $domains) === null) {
            $errors['hostname'] = 'This is not one of your domains or a subdomain of one.';
        }

        $site->environment = $req->input('environment') === 'production' ? 'production' : 'testing';

        $repoInput = trim($req->input('repo'));
        $repo = Validate::repo($repoInput);
        $site->repo = $repo ?? $repoInput;
        if ($repo === null) {
            $errors['repo'] = 'Use the form owner/repository (or paste the GitHub URL).';
        }

        $branch = trim($req->input('branch'));
        $site->branch = $branch !== '' ? $branch : 'main';
        if (!$site->isProduction() && !Validate::branch($site->branch)) {
            $errors['branch'] = 'Enter a valid branch name.';
        }

        $site->autoDeploy = $req->checkbox('auto_deploy');

        $php = $req->input('php', 'default');
        if (!isset(Php::versions($this->env)[$php])) {
            $errors['php'] = 'Choose an installed PHP version.';
        } else {
            $site->php = $php;
        }

        $web = trim($req->input('web_dir'), " \t/");
        $site->webDir = $web === '' ? '.' : $web;
        if ($site->webDir !== '.' && !Fs::isSafeRelative($site->webDir)) {
            $errors['web_dir'] = 'Use a relative path inside the repository, such as public.';
        }

        $shared = [];
        foreach (preg_split('/[\r\n,]+/', $req->text('shared_paths')) ?: [] as $line) {
            $p = trim(trim($line), '/');
            if ($p === '') {
                continue;
            }
            if (!Fs::isSafeRelative($p) || $p === '.git' || str_starts_with($p, '.git/')) {
                $errors['shared_paths'] = "Invalid shared path: {$p}";
                continue;
            }
            $shared[$p] = true;
        }
        $site->sharedPaths = array_keys($shared);
        if (count($site->sharedPaths) > self::MAX_SHARED) {
            $errors['shared_paths'] = 'At most ' . self::MAX_SHARED . ' shared paths.';
        }

        $max = $this->settings->int('max_keep_releases');
        $keep = $req->input('keep_releases');
        $site->keepReleases = is_numeric($keep) ? (int) $keep : $this->settings->int('keep_releases');
        if ($site->keepReleases < 1 || $site->keepReleases > $max) {
            $errors['keep_releases'] = "Choose a number between 1 and {$max}.";
        }

        $site->basePath = $this->basePath($req->input('base_path'), $host, $domains, $existing?->id, $errors);

        $script = ScriptBuilder::normalize($req->text('script'));
        $site->script = trim($script) === '' ? ScriptBuilder::defaultScript($this->env) : $script;
        $scriptErrors = ScriptBuilder::validate($site->script);
        if ($scriptErrors !== []) {
            $errors['script'] = implode(' ', $scriptErrors);
        }

        return [$site, $errors];
    }

    private function basePath(string $input, string $host, array $domains, ?string $selfId, array &$errors): string
    {
        $home = rtrim($this->ud->home, '/');
        $input = trim($input);
        if ($input === '') {
            $default = Validate::hostname($host) ? Domains::defaultBase($home, $host, $domains) : null;
            if ($default === null) {
                return '';
            }
            $input = $default;
        } elseif (str_starts_with($input, '~/')) {
            $input = $home . substr($input, 1);
        } elseif ($input[0] !== '/') {
            $input = $home . '/' . $input;
        }

        $path = Fs::normalize($input);
        if ($path === null) {
            $errors['base_path'] = 'Use a path inside your home directory, without "..".';
            return $input;
        }
        $realHome = realpath($home) ?: $home;
        $realAncestor = Fs::realAncestor($path);
        if ($path === $home || !Fs::isWithin($path, $home) || $realAncestor === null || !Fs::isWithin($realAncestor, $realHome)) {
            $errors['base_path'] = 'The path must be inside your home directory.';
        } elseif (Fs::isWithin($path, $this->ud->dir) || $path === $home . '/domains') {
            $errors['base_path'] = 'Choose a dedicated directory for this site.';
        } elseif (preg_match('~/(public_html|private_html)(/|$)~', substr($path, strlen($home))) === 1) {
            $errors['base_path'] = 'The base path must not be inside public_html or private_html: shared files such as .env would become public.';
        } else {
            foreach ($this->sites->all() as $other) {
                if ($other->id === $selfId || $other->basePath === '') {
                    continue;
                }
                // Nesting is fine (testing app in ~/domains/x/apps/test next to production in
                // ~/domains/x), but a base may not be shared or live inside another site's
                // releases/, shared/ or current.
                if ($path === $other->basePath || self::insideReserved($path, $other->basePath) || self::insideReserved($other->basePath, $path)) {
                    $errors['base_path'] = "This path conflicts with the site {$other->hostname} ({$other->basePath}).";
                    break;
                }
            }
        }
        return $path;
    }

    private static function insideReserved(string $path, string $base): bool
    {
        foreach (['releases', 'shared', 'current'] as $dir) {
            if (Fs::isWithin($path, $base . '/' . $dir)) {
                return true;
            }
        }
        return false;
    }
}
