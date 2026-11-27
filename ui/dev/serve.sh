#!/usr/bin/env bash
# Try the interface in a browser against a local fixture, without DirectAdmin.
#
#   KEEP=1 tests/integration/run.sh        # prints "Kept /tmp/tmp.XXXX" at the end
#   ui/dev/serve.sh /tmp/tmp.XXXX [port]    # then open the printed URL
#
# A background loop runs the deploy runner every 15 seconds, like DirectAdmin's cron
# (NO_CRON=1 disables it, to see how a stuck queue looks). GD_DEV_LATENCY_MS=800 slows
# every request down; `touch <fixture>/logged-out` simulates an expired session.
set -euo pipefail

FIX=$(cd "${1:?usage: serve.sh <fixture dir from KEEP=1 tests/integration/run.sh> [port]}" && pwd -P)
PORT=${2:-8090}
FAKE_PORT=$((PORT + 1))
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PHP=${PHP:-$(command -v php)}

[ -f "$ROOT/plugin/images/app.js" ] || { echo "Build the UI first: (cd ui && npm run build)"; exit 1; }

GD_FAKE_DIR=$FIX/fake GD_FAKE_REMOTES=$FIX/remotes "$PHP" -S "127.0.0.1:$FAKE_PORT" "$ROOT/tests/integration/fake-github.php" >/dev/null 2>&1 &
PIDS=$!
trap 'kill $PIDS 2>/dev/null || true' EXIT

export GIT_DEPLOY_TEST_MODE=1
export GIT_DEPLOY_HOME=$FIX/home
export GIT_DEPLOY_PLUGIN_DATA=$FIX/plugin-data
export GIT_DEPLOY_API_BASE=http://127.0.0.1:$FAKE_PORT
export GIT_DEPLOY_CLONE_BASE=file://$FIX/remotes
export GIT_DEPLOY_PHP_BIN=$PHP
export GIT_DEPLOY_COMPOSER=$FIX/bin/composer
export GIT_DEPLOY_PHP_DIR_GLOB=$FIX/no-php-dirs
export GIT_DEPLOY_DA_USERS_DIR=$FIX/da-users
export GD_DEV_FIXTURE=$FIX
export PHP_CLI_SERVER_WORKERS=4
unset USERNAME || true
# The integration test leaves the user suspended; the scheduler loop needs it active.
[ -d "$FIX/da-users/$(id -un)" ] && echo "suspended=no" > "$FIX/da-users/$(id -un)/user.conf"

if [ "${NO_CRON:-0}" != 1 ]; then
    (
        while sleep 15; do
            "$PHP" -c "$ROOT/plugin/php.ini" "$ROOT/plugin/bin/dispatcher.php" --foreground >/dev/null 2>&1 || true
        done
    ) &
    PIDS="$PIDS $!"
fi

SLUG=$(basename "$ROOT/plugin")   # DirectAdmin names plugin URLs after the plugin folder
echo "User:  http://127.0.0.1:$PORT/CMD_PLUGINS/$SLUG/"
echo "Admin: http://127.0.0.1:$PORT/CMD_PLUGINS_ADMIN/$SLUG/"
"$PHP" -S "127.0.0.1:$PORT" "$ROOT/ui/dev/router.php"
