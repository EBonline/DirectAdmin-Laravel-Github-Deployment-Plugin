<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Admin-level entry point (/CMD_PLUGINS_ADMIN/<plugin>/...): index.html returns the shell of
 * the Vue app, api.raw is its JSON API (overview, settings and access).
 */
final class AdminApp
{
    public const CRON_FILE = '/etc/cron.d/laravel_github_deploy';

    private Env $env;
    private Request $req;
    private Csrf $csrf;
    private string $identity;
    private string $base;

    public function __construct(?Request $req = null)
    {
        $this->env = Env::detect();
        $this->base = '/CMD_PLUGINS_ADMIN/' . $this->env->slug();
        $this->req = $req ?? Request::fromEnvironment();
        $pw = posix_getpwuid(posix_geteuid());
        $this->identity = is_array($pw) ? (string) $pw['name'] : (string) posix_geteuid();
        $this->csrf = new Csrf($this->env->dataDir . '/.admin-secret', 'admin|' . (getenv('USERNAME') ?: $this->identity));
    }

    public function handle(string $page): string
    {
        $api = $page === 'api';
        try {
            if ($api) {
                return (new AdminApi($this->env, $this->req, $this->csrf, $this->identity))->handle();
            }
            return Shell::render($this->env, $this->base, [
                'area' => 'admin',
                'base' => $this->base,
                'api' => $this->base . '/api.raw',
                'csrf' => $this->csrfToken(),
                'version' => $this->env->version(),
                'user' => (string) (getenv('USERNAME') ?: $this->identity),
                'blocked' => null,
            ]);
        } catch (\Throwable $e) {
            return $api
                ? Json::error(new ApiError(500, 'server', $e->getMessage()))
                : '<div class="gd-alert gd-alert-err">Laravel Github deploy: ' . h($e->getMessage()) . '</div>';
        }
    }

    /** The token needs a secret in data/; an unwritable data directory is reported by the app instead. */
    private function csrfToken(): string
    {
        try {
            return $this->csrf->token();
        } catch (\Throwable) {
            return '';
        }
    }
}
