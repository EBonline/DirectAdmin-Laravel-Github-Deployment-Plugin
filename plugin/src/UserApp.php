<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * User-level entry point (/CMD_PLUGINS/<plugin>/...). Pages (index.html, site.html, …) all
 * return the shell of the Vue app; api.raw is the JSON API it talks to. DirectAdmin runs
 * these scripts as the logged-in user.
 */
final class UserApp
{
    private Env $env;
    private UserData $ud;
    private Settings $settings;
    private Request $req;
    private Csrf $csrf;
    private string $base;

    public function __construct(?Request $req = null, ?UserData $ud = null)
    {
        $this->env = Env::detect();
        $this->base = '/CMD_PLUGINS/' . $this->env->slug();
        $this->ud = $ud ?? UserData::current();
        $this->settings = Settings::load($this->env);
        $this->req = $req ?? Request::fromEnvironment();
        $this->csrf = new Csrf($this->ud->secretFile(), 'user|' . $this->ud->user);
    }

    public function handle(string $page): string
    {
        $api = $page === 'api';
        try {
            $blocked = $this->blocked();
            if ($api) {
                if ($blocked !== null) {
                    return Json::error(new ApiError(403, $blocked['code'], $blocked['message']));
                }
                $this->ud->ensure();
                return (new UserApi($this->env, $this->ud, $this->settings, $this->req, $this->csrf))->handle();
            }
            if ($blocked === null) {
                $this->ud->ensure();
            }
            return Shell::render($this->env, $this->base, [
                'area' => 'user',
                'base' => $this->base,
                'api' => $this->base . '/api.raw',
                'csrf' => $blocked === null ? $this->csrf->token() : '',
                'version' => $this->env->version(),
                'user' => $this->ud->user,
                'blocked' => $blocked,
            ]);
        } catch (\Throwable $e) {
            return $api
                ? Json::error(new ApiError(500, 'server', $e->getMessage()))
                : '<div class="gd-alert gd-alert-err">Laravel Github deploy: ' . h($e->getMessage()) . '</div>';
        }
    }

    /** Why this account cannot use the plugin, if it cannot. */
    private function blocked(): ?array
    {
        if (posix_geteuid() === 0) {
            return [
                'code' => 'identity',
                'title' => 'Plugin scripts run as root',
                'message' => 'DirectAdmin must run user-level plugin scripts as the logged-in user. Ask your hosting administrator to check the DirectAdmin configuration.',
            ];
        }
        $daUser = getenv('USERNAME');
        if (is_string($daUser) && $daUser !== '' && $daUser !== $this->ud->user) {
            return [
                'code' => 'identity',
                'title' => 'Plugin scripts run as the wrong user',
                'message' => "Plugin scripts run as {$this->ud->user} instead of {$daUser}. DirectAdmin must run user-level plugin scripts as the logged-in user.",
            ];
        }
        if (!Access::isAllowed($this->env, $this->settings, $this->ud->user, $this->ud->shell)) {
            return [
                'code' => 'disabled',
                'title' => 'Laravel Github deploy is not enabled for your account',
                'message' => 'Deploy scripts run commands on the server as your user, so Laravel Github deploy is only available to accounts with SSH access. '
                    . 'Ask your hosting administrator to enable SSH access for your account, or to allow you in the Laravel Github deploy admin settings.',
            ];
        }
        return null;
    }
}
