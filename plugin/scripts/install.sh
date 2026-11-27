#!/bin/sh
# Laravel Github deploy — run as root by the DirectAdmin Plugin Manager on install (and by
# update.sh). Safe to run again at any time.
set -eu

PLUGIN_ID=laravel_github_deploy
# The Plugin Manager may name the folder after the uploaded file, so never assume it.
PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd -P)
PLUGIN_NAME=$(basename "$PLUGIN_DIR")
DATA_DIR="$PLUGIN_DIR/data"
PHP=/usr/local/bin/php
INI="$PLUGIN_DIR/php.ini"
CRON_FILE=/etc/cron.d/laravel_github_deploy
DA_BIN=/usr/local/directadmin/directadmin
ADMIN_LIST=/usr/local/directadmin/data/admin/admin.list

fail() { echo "Laravel Github deploy: $*"; exit 1; }

[ "$(id -u)" = "0" ] || fail "install.sh must run as root."
[ -f "$PLUGIN_DIR/plugin.conf" ] && grep -qx "id=$PLUGIN_ID" "$PLUGIN_DIR/plugin.conf" \
    || fail "could not find the plugin files next to install.sh ($PLUGIN_DIR)."
if [ "$PLUGIN_NAME" != "$PLUGIN_ID" ]; then
    echo "Note: the plugin was installed in $PLUGIN_DIR; its pages are at /CMD_PLUGINS/$PLUGIN_NAME."
    echo "      Upload the file as $PLUGIN_ID.tar.gz to get /CMD_PLUGINS/$PLUGIN_ID."
fi
[ -x "$PHP" ] || fail "$PHP not found. Install PHP with CustomBuild first."

"$PHP" -c "$INI" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' \
    || fail "PHP 8.3 or newer is required at $PHP (found $("$PHP" -r 'echo PHP_VERSION;'))."
"$PHP" -c "$INI" -r 'exit(extension_loaded("curl") && function_exists("posix_getpwuid") && function_exists("proc_open") ? 0 : 1);' \
    || fail "$PHP needs the curl and posix extensions and proc_open must not be disabled."

command -v git >/dev/null 2>&1 || fail "git is not installed (dnf/apt install git)."
GIT_VERSION=$(git --version | sed 's/[^0-9.]*\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/')
GIT_MAJOR=${GIT_VERSION%%.*}
GIT_MINOR=${GIT_VERSION#*.}
if [ "$GIT_MAJOR" -lt 2 ] || { [ "$GIT_MAJOR" -eq 2 ] && [ "$GIT_MINOR" -lt 31 ]; }; then
    fail "git 2.31 or newer is required (found $GIT_VERSION)."
fi
command -v runuser >/dev/null 2>&1 || [ -x /usr/sbin/runuser ] || [ -x /sbin/runuser ] || fail "runuser (util-linux) is required."
command -v timeout >/dev/null 2>&1 || fail "timeout (coreutils) is required."

# Owner of data/. DirectAdmin runs admin pages as the logged-in admin's own account (not
# diradmin), so data/ belongs to the main admin account: the one `da admin` names, else the
# first account in admin.list. An admin account chosen by hand (chown) is kept on updates.
valid_owner() { # an existing account other than root
    case "$1" in '' | *[!a-z0-9_.-]*) return 1 ;; esac
    _uid=$(id -u "$1" 2>/dev/null) && [ "$_uid" != 0 ]
}
is_admin() { # listed in admin.list (or there is no list to check against)
    [ ! -f "$ADMIN_LIST" ] || grep -qx "$1" "$ADMIN_LIST"
}
OWNER=
if [ -d "$DATA_DIR" ] && [ ! -L "$DATA_DIR" ] && [ -f "$ADMIN_LIST" ]; then
    CURRENT=$(stat -c %U "$DATA_DIR" 2>/dev/null || true)
    if valid_owner "$CURRENT" && is_admin "$CURRENT"; then OWNER=$CURRENT; fi
fi
if [ -z "$OWNER" ] && [ -x "$DA_BIN" ]; then
    MAIN=$(timeout 10 "$DA_BIN" admin </dev/null 2>/dev/null | head -n 1 | tr -d '[:space:]' || true)
    if valid_owner "$MAIN" && is_admin "$MAIN"; then OWNER=$MAIN; fi
fi
if [ -z "$OWNER" ] && [ -f "$ADMIN_LIST" ]; then
    while IFS= read -r a || [ -n "$a" ]; do
        if valid_owner "$a"; then OWNER=$a; break; fi
    done <"$ADMIN_LIST"
fi
if [ -z "$OWNER" ]; then
    OWNER=diradmin
    valid_owner "$OWNER" || OWNER=root
    echo "WARNING: could not find the main DirectAdmin admin account, so data/ is given to $OWNER."
    echo "         Admin pages run as the admin who is logged in and cannot save settings until"
    echo "         $DATA_DIR belongs to that account."
fi
OWNER_GROUP=$(id -gn "$OWNER")

# Permissions: GUI wrappers and CLI scripts.
find "$PLUGIN_DIR" -path "$DATA_DIR" -prune -o -exec chown root:root {} +
find "$PLUGIN_DIR" -path "$DATA_DIR" -prune -o -type d -exec chmod 755 {} +
find "$PLUGIN_DIR" -path "$DATA_DIR" -prune -o -type f -exec chmod 644 {} +
chmod 755 "$PLUGIN_DIR"/user/* "$PLUGIN_DIR"/admin/* "$PLUGIN_DIR"/scripts/*.sh

# Data directory (kept across updates), owned by the main admin account (OWNER above):
#   data/             admin 0755  admin pages write settings.json here
#   data/access/      admin 0711  per-user allow/deny files (0644), not listable
#   data/status/      admin 0750  written by root, read by the admin page
#   data/run/         root  0700  locks
# The admin account can write here, so never follow a symbolic link as root.
for p in "$DATA_DIR" "$DATA_DIR/access" "$DATA_DIR/status" "$DATA_DIR/run" "$DATA_DIR/settings.json" "$DATA_DIR/.admin-secret"; do
    [ ! -L "$p" ] || fail "$p is a symbolic link. Remove it and run install.sh again."
done
mkdir -p "$DATA_DIR/access" "$DATA_DIR/status" "$DATA_DIR/run"
chown "$OWNER:$OWNER_GROUP" "$DATA_DIR" "$DATA_DIR/access" "$DATA_DIR/status"
chmod 755 "$DATA_DIR"
chmod 711 "$DATA_DIR/access"
chmod 750 "$DATA_DIR/status"
chown root:root "$DATA_DIR/run"
chmod 700 "$DATA_DIR/run"
if [ ! -f "$DATA_DIR/settings.json" ]; then
    "$PHP" -c "$INI" -r 'require "'"$PLUGIN_DIR"'/src/bootstrap.php"; GitDeploy\Settings::save(GitDeploy\Env::detect(), GitDeploy\Settings::DEFAULTS);'
fi
chown "$OWNER:$OWNER_GROUP" "$DATA_DIR/settings.json"
chmod 644 "$DATA_DIR/settings.json"
find "$DATA_DIR/access" -type f -exec chown "$OWNER:$OWNER_GROUP" {} + -exec chmod 644 {} +
find "$DATA_DIR/status" -type f -exec chown "$OWNER:$OWNER_GROUP" {} + -exec chmod 640 {} +
[ ! -f "$DATA_DIR/.admin-secret" ] || { chown "$OWNER:$OWNER_GROUP" "$DATA_DIR/.admin-secret"; chmod 600 "$DATA_DIR/.admin-secret"; }

# Composer for deploy scripts, unless CustomBuild already installed it.
if [ ! -f /usr/local/bin/composer ] && [ ! -f "$PLUGIN_DIR/bin/composer.phar" ]; then
    echo "Downloading Composer..."
    TMP=$(mktemp -d)
    if curl -fsSL -o "$TMP/composer.phar" https://getcomposer.org/download/latest-stable/composer.phar \
        && curl -fsSL -o "$TMP/composer.sha256" https://getcomposer.org/download/latest-stable/composer.phar.sha256sum \
        && [ "$(sha256sum "$TMP/composer.phar" | cut -d' ' -f1)" = "$(cut -d' ' -f1 "$TMP/composer.sha256")" ]; then
        install -m 755 "$TMP/composer.phar" "$PLUGIN_DIR/bin/composer.phar"
        echo "Composer installed in $PLUGIN_DIR/bin/composer.phar"
    else
        echo "WARNING: could not download Composer; deploy scripts will use 'composer' from PATH."
    fi
    rm -rf "$TMP"
fi

# Menu links must use the folder name DirectAdmin serves the plugin under.
printf '<a href="/CMD_PLUGINS/%s">Laravel Github deploy</a>\n' "$PLUGIN_NAME" > "$PLUGIN_DIR/hooks/user_txt.html"
printf '<a href="/CMD_PLUGINS_ADMIN/%s">Laravel Github deploy</a>\n' "$PLUGIN_NAME" > "$PLUGIN_DIR/hooks/admin_txt.html"
chmod 644 "$PLUGIN_DIR"/hooks/*.html

# The deploy loop: every minute, root dispatches per-user runs (as the user).
# The plugin is located by its id at run time (and skipped while deactivated), so the
# cron job keeps working whatever folder name the Plugin Manager uses.
cat > "$CRON_FILE" <<CRON
# Laravel Github deploy for DirectAdmin — checks GitHub and runs deployments.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * root for c in /usr/local/directadmin/plugins/*/plugin.conf; do grep -qx 'id=$PLUGIN_ID' "\$c" && grep -qx 'active=yes' "\$c" && d=\$(dirname "\$c") && $PHP -c "\$d/php.ini" "\$d/bin/dispatcher.php" && break; done >/dev/null 2>&1
CRON
chmod 644 "$CRON_FILE"

echo "Laravel Github deploy installed. GitHub is checked every few minutes for each configured site."
echo "Settings are managed by the DirectAdmin admin account '$OWNER' (owner of $DATA_DIR)."
exit 0
