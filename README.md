# Laravel Github deploy for DirectAdmin

Forge-style zero-downtime deployments from GitHub, managed by DirectAdmin users themselves.

- **Testing sites** follow a branch. GitHub is checked every 5 minutes and every new commit is deployed.
- **Production sites** follow GitHub Releases. When a new release is published (not a draft or a pre-release), its tag is deployed.
- Every app runs from `current`, a symlink to a directory in `releases/`. A deploy builds the new release next to the running one. The symlink switches only after the whole deploy script has succeeded. If a step fails, the release is thrown away and the site keeps running.
- Rollback to any retained release, deploy a specific release or commit, edit the shared `.env`, and follow a live deploy log.
- Admins get an overview of every site plus global settings and per-user access.
- A Vue interface that keeps you informed: deployments are followed live from *queued* to *live* with a running log, every action shows what it is doing and how it went, and destructive actions ask first.

## How it works

```
/etc/cron.d/laravel_github_deploy (root, every minute)
 └─ bin/dispatcher.php            lists DirectAdmin users that may use the plugin and have sites
     └─ bin/worker.php <user>     one per user (bounded), as root:
         ├─ runuser -u <user> -- bin/runner.php      ← all git, file and script work runs as the user
         ├─ reads the runner's JSON summary from stdout
         ├─ systemctl reload php-fpmXY            (optional, for activated sites)
         └─ data/status/<user>.json               (admin overview)

bin/runner.php (as the user), per site:
  queued manual action? → run it
  poll interval passed? → ask GitHub (conditional request, ETag) → new target? → deploy
```

The interface is a Vue 3 app (`ui/`, built into `plugin/images/app.js` and `app.css`). Every page (`user/*.html`, `admin/index.html`) returns the same small shell with boot data; the app then talks to a JSON API at `user/api.raw` and `admin/api.raw`, which DirectAdmin runs as the logged-in user and the logged-in admin's own account (for example `admin`) respectively:

```
GET  api.raw?action=overview|site|site_form|refs|env|log|settings|csrf
POST api.raw?action=save_site|check_repo|deploy|deploy_ref|rollback|cancel_queue|…   body: csrf=…&payload=<base64url JSON>
```

Writes carry the CSRF token and their data as one base64url-encoded JSON field, so deploy scripts and `.env` files arrive byte-for-byte whatever DirectAdmin does to POST data. Errors come back as `{"ok":false,"error":{"code","message","fields"}}` with a matching HTTP status (`422` validation, `409` busy, `403` csrf, …).

Root never runs user code and never reads files the user controls. It only checks whether `~/.laravel-github-deploy/sites.json` exists, starts `runuser`, parses the runner's stdout, and reloads PHP-FPM versions taken from an allow-list.

### Layout of a site

```
<base>/                        default ~/domains/<domain>, or ~/domains/<parent>/apps/<sub> for subdomains
├── releases/2611241430/       one directory per deploy, named yymmddHHMM (git checkout of the commit)
├── shared/.env                shared paths, symlinked into every release
├── shared/storage/
└── current -> releases/2611241430
```

**The plugin does not touch `public_html`.** After the first deploy, the user sets the domain's document root in DirectAdmin to `<base>/current/<web dir>`, for example `~/domains/example.com/current/public`. If the domain has a separate `private_html`, its SSL document root needs the same change. The site page shows the exact path and has a **Check document root** button. The check places a one-time file in `current/public` and requests it over HTTPS (falling back to HTTP).

### Deploy pipeline

1. Take a per-site lock. A site is never deployed twice at once.
2. Find the target:
   - **Testing:** the head commit of the branch.
   - **Production:** `GET /releases/latest`, which skips drafts and pre-releases.
3. Fetch into a bare cache at `~/.laravel-github-deploy/repos/<site>.git` and resolve the commit (for production, the tag's commit).
4. Run the deploy script with bash as the user. It has a clean environment and the admin's timeout. The log first lists the toolchain the script will find on `PATH`, for example `Toolchain: PHP 8.3.14, Composer 2.8.4, Node.js 22.11.0, npm 10.9.0`. A missing tool shows as `Node.js not found`, followed by the `PATH` that was searched. A Node.js older than 22 is marked *too old*.
   - **`$CREATE_RELEASE()`** clones from the cache into `releases/<id>`, checks out the commit, updates submodules, writes `REVISION`, and links the shared paths. On the first deploy, `shared/` is seeded from the repository: `storage/` is moved there, and `.env` is created from `.env.example`.
   - **`$ACTIVATE_RELEASE()`** switches `current` atomically (`rename` of a new symlink) and removes old releases beyond the keep count. It never removes the live release or the one it replaced.
   - **`$RESTART_QUEUES()`** runs `php artisan queue:restart` in the live release.
   - **`$REQUIRE_NODE()`** stops the deployment unless Node.js 22 or newer and `npm` are on the script's `PATH`, and logs their versions. It sees `PATH` as the script has set it so far, so a `PATH` change above it counts. When they are missing, the error names the `PATH` that was searched. When Node.js is too old, it names the version and path found. If nvm, Volta or fnm is installed in the home directory, the error also says what to do. For example, it gives `nvm install 22` to run once over SSH when nvm has no new enough version.
5. **Failure before activation:** the release directory is removed and `current` is never touched. **Failure after activation:** the release stays live and the deploy is marked *failed after activation*.
6. A target that failed is **not retried automatically**. Push a fix, publish a new release, or press *Deploy now*.

Default deploy script (Laravel):

```bash
$CREATE_RELEASE()

cd $DEPLOY_RELEASE_DIRECTORY

$DEPLOY_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f package.json ]; then
    $REQUIRE_NODE()
    npm ci
    npm run build
fi

$DEPLOY_PHP artisan storage:link
$DEPLOY_PHP artisan migrate --force
$DEPLOY_PHP artisan optimize

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
```

**Variables available to the script:**

| Variable(s) | Meaning |
|---|---|
| `DEPLOY_RELEASE_DIRECTORY` | the new release |
| `DEPLOY_SITE_PATH` | the `current` symlink |
| `DEPLOY_SITE_ROOT` | the base path |
| `DEPLOY_SHARED_DIRECTORY` | the base path's `shared/` directory |
| `DEPLOY_ID` | the deploy id |
| `DEPLOY_ENVIRONMENT` | `testing` or `production` |
| `DEPLOY_BRANCH`, `DEPLOY_TAG`, `DEPLOY_COMMIT` | what is being deployed |
| `DEPLOY_COMMIT_MESSAGE`, `DEPLOY_AUTHOR` | commit details |
| `DEPLOY_MANUAL` | `1` when started by hand |
| `DEPLOY_PHP` | the selected CustomBuild PHP binary; its directory is also first on `PATH` |
| `DEPLOY_COMPOSER` | runs Composer with that PHP |

Forge aliases are also exported, so Forge scripts work unchanged: `FORGE_RELEASE_DIRECTORY`, `FORGE_SITE_PATH`, `FORGE_SITE_ROOT`, `FORGE_SITE_BRANCH`, `FORGE_PHP`, `FORGE_COMPOSER`, `FORGE_DEPLOY_COMMIT`, `FORGE_DEPLOY_MESSAGE`, `FORGE_DEPLOY_AUTHOR`, `FORGE_MANUAL_DEPLOY`, `FORGE_SITE_USER`.

## Requirements

- DirectAdmin with the Evolution skin (other skins work through the `hooks/*_txt.html` menu links). The plugin follows Evolution's Light/Dark/Auto setting, and switches live when the mode changes. Other skins show the light theme.
- Linux with `runuser`, `timeout` and `setsid` (util-linux / coreutils).
- PHP 8.3 or newer at `/usr/local/bin/php` (CustomBuild), with the curl and posix extensions. The plugin runs PHP with its own `php.ini`, so `disable_functions` hardening in the server's php.ini does not affect it.
- git 2.31 or newer.
- Outbound HTTPS to `api.github.com` and `github.com`.
- Composer is used from `/usr/local/bin/composer`. If it is missing, `install.sh` downloads `composer.phar` and verifies its SHA-256.
- Node.js 22 or newer with npm, *optional*: only for apps that build frontend assets during the deploy. It can be installed system-wide (the *Diagnostics* card shows whether it is on `/usr/local/bin:/usr/bin:/bin`), set by the admin (see *Admin settings*), or installed per user with nvm, which deploy scripts find by themselves. The admin account cannot look in users' home directories, so each user's cron run reports the nvm Node.js its deploy scripts use, and the *Diagnostics* card lists it per user.

## Installing

In DirectAdmin go to **Admin → Plugin Manager → Add plugin** and add the latest release from this URL:

```
https://raw.githubusercontent.com/EBonline/DirectAdmin-Laravel-Github-Deployment-Plugin/refs/heads/dist/laravel_github_deploy.tar.gz
```

Then install and activate it. The Plugin Manager asks for your admin login password. Servers installed this way can be updated from the Plugin Manager (see *Updating*).

Instead of the URL, you can upload `laravel_github_deploy.tar.gz` from a [GitHub release](https://github.com/EBonline/DirectAdmin-Laravel-Github-Deployment-Plugin/releases), or build it yourself:

```bash
./build.sh                      # → dist/laravel_github_deploy.tar.gz
```

Building needs Node.js 22 or newer for the interface (`ui/`). The DirectAdmin server only needs Node.js for apps whose deploy script builds frontend assets (see *Requirements*). `SKIP_UI_BUILD=1 ./build.sh` packages interface files that are already built.

Upload the file under exactly that name. The Plugin Manager can name the plugin folder, and therefore its URLs, after the uploaded file. The plugin works from any folder name, but `laravel_github_deploy.tar.gz` gives the expected `/CMD_PLUGINS/laravel_github_deploy`.

The Plugin Manager runs `scripts/install.sh` as root. The script:

- checks PHP, its extensions, git, `runuser` and `timeout`;
- creates `data/` and gives it to the main DirectAdmin admin account (see below);
- installs Composer if needed;
- writes `/etc/cron.d/laravel_github_deploy`.

Re-running `scripts/install.sh` is safe. `update.sh` runs it after updates, and `data/` is kept.

**Who owns `data/`.** DirectAdmin runs admin pages as the account of the admin who is logged in, not as `diradmin`. Those pages save settings in `data/`, so `install.sh` gives `data/` to the main admin account, in this order:

1. the current owner of `data/`, if it is listed in `/usr/local/directadmin/data/admin/admin.list`, so an account you chose by hand stays the owner after updates;
2. the account `da admin` prints;
3. the first account in `admin.list`.

Only that account can change the settings and see every user's sites. Other admin accounts get a read-only notice naming it. To hand the plugin to another admin account, `chown` `data/`, `data/access/`, `data/status/` and the files in them to that account, then run `install.sh` again.

**After installing, open Admin → Laravel Github deploy and check the *Diagnostics* card.** Everything should be green, and *Last cron run* should show a time within the last minute.

### First checks on a real server

The DirectAdmin documentation says plugin scripts run as *"the DirectAdmin/UNIX user account that triggered the request"*, and the plugin relies on that. On a test server, confirm the following:

1. **Admin → Laravel Github deploy:** *Admin scripts run as* shows your admin account (for example `admin`), and the data directory is *writable*. If the page says the data directory belongs to another account, run the install command it shows as root.
2. **User → Laravel Github deploy:** the page loads. If it says *"Plugin scripts run as …"*, DirectAdmin is not running user scripts as the user.
3. Save a deploy script that contains `&`, `<`, quotes and `$`, then reopen the site settings. It must be unchanged. If saving shows *"The request arrived without its data"*, DirectAdmin is not passing POST data to `api.raw`.
4. Press **Deploy now**. The site page shows *Queued*, then *Deploying* with a running timer and the end of the log, and a notification when it is done. While deploying, the log page follows the output every 1.5 seconds.

## Updating

New versions come from GitHub through DirectAdmin's own Plugin Manager. `plugin.conf` points `version_url` and `update_url` at the `dist` branch of this repository, which always holds the latest stable release.

1. **Admin → Plugin Manager** shows when a newer version is available. DirectAdmin caches the check, so a new release can take about 30 minutes to appear.
2. Press **Update**. DirectAdmin downloads the package, unpacks it over the plugin folder and runs `scripts/update.sh` as root, which runs `install.sh` again.
3. Open **Admin → Laravel Github deploy**. The version next to *Administration* should be the new one, and the *Diagnostics* card should be green.

`data/` (settings and access overrides), users' sites and `~/.laravel-github-deploy` folders, and the cron job are kept. Update while no deployment is running: the files are replaced underneath any plugin process that is running at that moment.

**Versions without update URLs.** Version 0.2.0 and older shipped with empty update URLs, so the Plugin Manager cannot update them. Install the next release by hand once: upload its `laravel_github_deploy.tar.gz` from the [releases page](https://github.com/EBonline/DirectAdmin-Laravel-Github-Deployment-Plugin/releases), or add it from the URL under *Installing*. After that, the Plugin Manager can update it.

## Using it (DirectAdmin user)

1. **Settings:** add a GitHub [fine-grained personal access token](https://github.com/settings/personal-access-tokens/new).
   - Repository access: only the repositories you deploy.
   - Repository permission: *Contents: Read-only* (*Metadata: Read-only* is added automatically).
   - The token is stored in `~/.laravel-github-deploy/credentials.json` (mode 0600). It is never written into repositories or passed to deploy scripts.
   - A site can use its own token instead of the default.
2. **Add site:**
   - Fill in the domain or subdomain, choose *Testing* (with a branch) or *Production* (releases), and enter the repository as `owner/repo`.
   - Choose the PHP version, web directory (`public`), shared paths (`.env`, `storage`) and the number of releases to keep.
   - Adjust the deploy script if needed.
   - When you save, the plugin checks that the token can read the repository and, for testing sites, that the branch exists.
3. The first deployment starts within a minute.
4. **Edit .env:** fill in `shared/.env`, for example `APP_KEY` and the database settings, then press **Deploy now**.
5. Change the document root as shown on the site page, then press **Check document root**.
6. From then on:
   - **Testing:** push to the branch. The commit is deployed within about 5 minutes.
   - **Production:** publish a release on GitHub. It is deployed within about 5 minutes.
   - **Deploy now**, **Choose a release/commit…** and **Roll back** are queued and start within a minute.
   - Turning **Auto-deploy** off keeps checking GitHub and shows *"New release available"*, but deploys only when you ask.

Laravel's scheduler and queue workers are not managed by the plugin. Run them with DirectAdmin cron jobs pointing at `current`, for example `cd ~/domains/example.com/current && php artisan schedule:run`. Workers pick up new code after `$RESTART_QUEUES()`.

**Node.js:** when a release contains a `package.json`, the default script runs `$REQUIRE_NODE()` before `npm ci` and `npm run build`. If `node` or `npm` is not on `PATH`, or Node.js is older than 22, the deployment stops with a clear error and the live release keeps running. Earlier versions skipped the build without a warning in that case. Deploy scripts start with this `PATH`, in order:

1. the selected PHP's directory;
2. the admin's Node.js binary, if set (see *Admin settings*);
3. `~/.local/bin` and `~/bin`;
4. the Node.js installed with nvm, if any;
5. `/usr/local/bin:/usr/bin:/bin`.

**nvm needs no changes to the deploy script.** Deploy scripts get `~/.nvm/versions/node/<version>/bin` (or `~/.config/nvm/…`) on `PATH`, whatever versions are installed:

- nvm's default version (`nvm alias default`, following aliases such as `lts/*`), when it is 22 or newer;
- otherwise the newest installed version that is;
- with no version 22 or newer installed, the newest one, so the error names it.

The site settings page warns when the script uses npm but npm, or Node.js 22 or newer, is not on that `PATH`. It also offers the new default script to sites that still use the previous one. If the app needs no frontend build, remove the `package.json` block from the script.

## Admin settings

**Admin → Laravel Github deploy** has these settings:

| Setting | Default |
|---|---|
| Check GitHub every | 5 minutes |
| Deploy timeout | 15 minutes |
| Default releases to keep | 5 |
| Maximum releases a user may keep | 20 |
| Users processed in parallel | 4 |
| Reload PHP-FPM after activation | on |
| Node.js binary for deploy scripts | empty (from `PATH`) |
| npm binary for deploy scripts | empty (next to the Node.js binary, else from `PATH`) |
| Who may use Laravel Github deploy | users with SSH access |

- **Reload PHP-FPM after activation** is a graceful `systemctl reload php-fpmXY` for the site's PHP version. It clears PHP's realpath cache so the new release is served immediately instead of after up to 2 minutes.
- **Who may use Laravel Github deploy** can be: users with SSH access (default), all users, or an allow list.
- **Node.js and npm binaries** override what deploy scripts find on `PATH`, for example `/opt/alt/alt-nodejs22/root/usr/bin/node` on CloudLinux.
  - Each user gets `node`, `npm` and `npx` symlinks to them in `~/.laravel-github-deploy/node-bin/`, which comes right after the PHP directory on the deploy `PATH`. The files may have any name (`node-22` finds `npm-22` and `npx-22` next to it).
  - When you save, the plugin runs the binaries. They must be executable by other users, and Node.js must be 22 or newer. npm is tested against that Node.js. Saving is refused with a field error otherwise.
  - A deploy script that changes `PATH` itself (for example with nvm) still uses its own Node.js.
  - The deploy log names the override, and the *Diagnostics* card checks the configured paths. Unlike the automatic Node.js and npm rows, these count as problems when they break.
- **Per-user allow/deny overrides** take precedence over the policy. They are stored in `data/access/<user>`, a directory with mode 0711 so users can only look up their own name.

A deploy script runs arbitrary commands as its user. That is why the default policy only allows accounts that already have SSH access. On CloudLinux/CageFS, note that `runuser` from root does not enter the user's cage.

## Files

| Path | Contents |
|---|---|
| `~/.laravel-github-deploy/` (0700) | `sites.json`, `credentials.json`, `state/`, `logs/`, `queue/`, `repos/` (bare caches), `locks/`, `tmp/`, `node-bin/` (links to the admin's Node.js override) |
| `plugin/data/settings.json` | admin settings (owned by the main admin account, like `data/` itself) |
| `plugin/data/access/` | per-user overrides (main admin account, 0711) |
| `plugin/data/status/` | overview data written by root for the main admin account (0750) |
| `plugin/data/run/` | root locks (root, 0700) |

When you remove a site in the UI, its configuration, history, logs and cache are deleted. Deployed files are left untouched. Uninstalling the plugin removes the cron job only.

## Development

```bash
composer install
vendor/bin/phpunit                 # PHP unit tests
tests/integration/run.sh           # end-to-end: local bare repo + fake GitHub API (php -S)

cd ui && npm ci
npm run typecheck && npm test      # TypeScript check and Vitest unit tests
npm run build                      # → plugin/images/app.js, app.css
npm run dev                        # rebuild on every change

./build.sh                         # dist/laravel_github_deploy.tar.gz
```

**Trying the interface without DirectAdmin:** `KEEP=1 tests/integration/run.sh` leaves its fixture behind (*"Kept /tmp/…"*). `ui/dev/serve.sh /tmp/…` then serves the plugin at `http://127.0.0.1:8090/CMD_PLUGINS/plugin/` (DirectAdmin names the URLs after the plugin folder) with a fake GitHub, and runs the scheduler every 15 seconds so deployments really happen. `NO_CRON=1` stops the scheduler (to see a stuck queue), `GD_DEV_LATENCY_MS=800` slows every request, and `touch /tmp/…/logged-out` simulates an expired DirectAdmin session. Like Evolution, the page shows the plugin in a frame, and its *Evolution mode* switch (Light/Dark/Auto) sets the `mode:light`/`mode:dark` class the plugin follows.

Frontend layout: `ui/src/api/` (client and types), `composables/` (polling, toasts, confirmations, connection and session handling, deploy notifications), `components/`, `user/pages/` (one per URL: `index.html`, `site.html`, `edit.html`, `env.html`, `log.html`, `ref.html`, `settings.html`) and `admin/`.

The integration test runs the real runner, helper, dispatcher, worker and UI scripts in a temporary directory. It sets `GIT_DEPLOY_TEST_MODE=1`, which enables `GIT_DEPLOY_*` path and URL overrides that are ignored in production. It covers:

- first deploy, shared-path seeding, no-op polls (304) and new commits;
- failing builds without retry loops, cleanup and production releases;
- rollback without redeploy, deploying a chosen commit, and auto-deploy off;
- concurrent runs, recovery of interrupted deploys, and the dispatcher chain;
- the JSON API: CSRF, entity-encoded and missing POST data, byte-exact scripts and `.env` files, field errors, busy conflicts, the repository check, the document-root check, access overrides and the admin settings.

### Releasing

Releases are built by GitHub Actions (`.github/workflows/release.yml`) when a version tag is pushed. Bump the version and get it onto `main` (directly or through a pull request):

```bash
V=0.3.1
sed -i "s/^version=.*/version=$V/" plugin/plugin.conf
(cd ui && npm version "$V" --no-git-tag-version)       # keeps ui/package.json in step
git commit -am "Release $V"
```

Then tag the commit on `main` and push only the tag:

```bash
git checkout main && git pull
git tag "v$V"
git push origin "v$V"
```

**Do not create or publish the release in the GitHub interface.** This repository has immutable releases: once a release is published, no files can be attached to it, and its tag can never be reused, not even after the release is deleted. A release published by hand therefore stays without the package. The workflow creates the release itself, as a draft that it publishes once the package is attached. You can edit the title and notes afterwards.

The workflow:

1. checks that the tag is `v` + `version=` from `plugin/plugin.conf`, and that a stable version is tagged on `main`;
2. runs the PHP unit and integration tests and the interface checks and tests, then builds the package with `build.sh`;
3. creates the GitHub release with `laravel_github_deploy.tar.gz` and its `.sha256` attached and generated notes. A draft release that already exists for the tag gets the files and stays a draft for you to publish. A release that was already published only gets a warning;
4. for stable versions, replaces the `dist` branch with the package and a `version.txt`. Servers check that branch, so this is the step that offers the update to them. It never publishes a version older than the one already on `dist`.

If a run fails after the release was published, fix the cause and release the next patch version: the tag cannot be used again.

A version with a `-`, such as `0.3.0-rc.1` (tag `v0.3.0-rc.1`), becomes a GitHub pre-release only. It can be tagged on any branch and is not offered to servers; to try it, upload its package by hand.

The `dist` branch is managed by the workflow and holds a single commit. Do not commit to it.
