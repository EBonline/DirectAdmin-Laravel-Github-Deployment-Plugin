#!/usr/bin/env bash
# End-to-end test of the deploy loop against a local bare repository and a fake
# GitHub API. Runs entirely in a temporary directory; needs php, git and curl.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PLUGIN=$ROOT/plugin
HERE=$ROOT/tests/integration
PHP=${PHP:-$(command -v php)}
T=$(mktemp -d)
T=$(cd "$T" && pwd -P)
SERVER_PID=

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
    if [ "${KEEP:-0}" = 1 ]; then echo "Kept $T"; else rm -rf "$T"; fi
}
trap cleanup EXIT

PASS=0
pass() { PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗ %s\033[0m\n' "$1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | sed 's/^/      /'; exit 1; }
assert_eq() { [ "$1" = "$2" ] && pass "$3" || fail "$3" "expected: $2"$'\n'"actual:   $1"; }
assert_contains() { case "$1" in *"$2"*) pass "$3" ;; *) fail "$3" "missing: $2"$'\n'"in: $(printf '%s' "$1" | tail -c 1500)" ;; esac; }
assert_not_contains() { case "$1" in *"$2"*) fail "$3" "unexpected: $2" ;; *) pass "$3" ;; esac; }
section() { printf '\n\033[1m%s\033[0m\n' "$1"; }
json() { "$PHP" "$HERE/json.php" "$@"; }

# ---------------------------------------------------------------- fixtures
HOME_DIR=$T/home
DATA=$HOME_DIR/.laravel-github-deploy
mkdir -p "$HOME_DIR/domains/example.test/public_html" "$T/remotes/acme" "$T/fake" "$T/plugin-data" "$T/bin"
echo "DirectAdmin default page" > "$HOME_DIR/domains/example.test/public_html/index.html"
echo '[]' > "$T/fake/releases.json"

cat > "$T/bin/composer" <<'PHPEOF'
<?php echo "composer " . implode(' ', array_slice($argv, 1)) . "\n";
PHPEOF

git init --quiet --bare "$T/remotes/acme/app.git"
git -C "$T/remotes/acme/app.git" symbolic-ref HEAD refs/heads/main
WORK=$T/work
git init --quiet -b main "$WORK"
git -C "$WORK" config user.email dev@example.test
git -C "$WORK" config user.name "Dev Eloper"
git -C "$WORK" remote add origin "$T/remotes/acme/app.git"
mkdir -p "$WORK/public" "$WORK/storage/app/public" "$WORK/storage/logs"
touch "$WORK/storage/app/public/.gitignore" "$WORK/storage/logs/.gitignore"
echo "APP_NAME=Example" > "$WORK/.env.example"
cat > "$WORK/artisan" <<'PHPEOF'
<?php echo "artisan " . implode(' ', array_slice($argv, 1)) . " in " . getcwd() . "\n";
PHPEOF

commit() { # message, version
    echo "<?php echo 'version $2';" > "$WORK/public/index.php"
    git -C "$WORK" add -A
    git -C "$WORK" commit --quiet -m "$1"
    git -C "$WORK" push --quiet origin main 2>/dev/null
    git -C "$WORK" rev-parse HEAD
}
SHA1=$(commit "Initial version" 1)

PORT=$(( 20000 + RANDOM % 20000 ))
GD_FAKE_DIR=$T/fake GD_FAKE_REMOTES=$T/remotes "$PHP" -S "127.0.0.1:$PORT" "$HERE/fake-github.php" >"$T/fake/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.1; done

export GIT_DEPLOY_TEST_MODE=1
export GIT_DEPLOY_HOME=$HOME_DIR
export GIT_DEPLOY_PLUGIN_DATA=$T/plugin-data
export GIT_DEPLOY_API_BASE=http://127.0.0.1:$PORT
export GIT_DEPLOY_CLONE_BASE=file://$T/remotes
export GIT_DEPLOY_PHP_BIN=$PHP
export GIT_DEPLOY_COMPOSER=$T/bin/composer
export GIT_DEPLOY_PHP_DIR_GLOB=$T/no-php-dirs
ME=$(id -un)
unset USERNAME || true

runner() { "$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/bin/runner.php" --force-poll "$@"; }
ui() { # page [query]: the HTML shell of a user page
    QUERY_STRING=${2:-} POST= USERNAME=$ME "$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/app/user.php" "$1"
}
b64url() { printf '%s' "$1" | base64 -w0 | tr '+/' '-_' | tr -d '='; }
apiraw() { # app query post: raw response of api.raw (status line, headers, JSON)
    QUERY_STRING=$2 POST=$3 REQUEST_METHOD=$([ -n "$3" ] && echo POST || echo GET) USERNAME=$ME \
        "$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/app/$1.php" api
}
api() { # action [query] [payload-json] [csrf]: GET without a payload, POST with one
    if [ -n "${3:-}" ]; then
        apiraw user "action=$1${2:+&$2}" "csrf=${4:-$CSRF}&payload=$(b64url "$3")"
    else
        apiraw user "action=$1${2:+&$2}" ""
    fi
}
adminapi() { # action [payload-json]
    if [ -n "${2:-}" ]; then
        apiraw admin "action=$1" "csrf=$ACSRF&payload=$(b64url "$2")"
    else
        apiraw admin "action=$1" ""
    fi
}
jget() { # response dot.path: a value from the JSON body of an api response
    printf '%s' "$1" | sed '1,/^$/d' > "$T/resp.json"
    json "$T/resp.json" "$2"
}

TEST_SCRIPT='$CREATE_RELEASE()

cd $DEPLOY_RELEASE_DIRECTORY
echo "building $DEPLOY_COMMIT on [$DEPLOY_BRANCH] tag [$DEPLOY_TAG] env $DEPLOY_ENVIRONMENT manual=$DEPLOY_MANUAL"
echo "forge release dir $FORGE_RELEASE_DIRECTORY"
test -f public/index.php
if [ -f FAIL_BUILD ]; then echo "failing on purpose"; exit 3; fi
if [ -f SLOW_BUILD ]; then sleep 3; fi
$DEPLOY_COMPOSER install --no-dev
$DEPLOY_PHP artisan migrate --force
if env | grep -q "^GIT_CONFIG_VALUE_0="; then echo "TOKEN LEAKED"; fi
if [ -f package.json ]; then
    if [ -f NO_NODE ]; then PATH=/nonexistent; elif [ -f OLD_NODE ]; then PATH="$HOME/old-node/bin:$PATH"; elif [ ! -f KEEP_PATH ]; then PATH="$HOME/node/bin:$PATH"; fi
    $REQUIRE_NODE()
    npm ci
    npm run build
fi

$ACTIVATE_RELEASE()

$RESTART_QUEUES()'

mkdir -p "$DATA"
chmod 700 "$DATA"
S="$TEST_SCRIPT" H="$HOME_DIR" D="$DATA" "$PHP" -r '
$script = getenv("S"); $h = getenv("H");
$common = ["auto_deploy" => true, "php" => "default", "web_dir" => "public", "shared_paths" => [".env", "storage"], "script" => $script, "created_at" => time(), "repo" => "acme/app"];
$sites = ["sites" => [
  "test-example-test" => ["id" => "test-example-test", "hostname" => "test.example.test", "base_path" => "$h/domains/example.test/apps/test", "environment" => "testing", "branch" => "main", "keep_releases" => 3] + $common,
  "example-test" => ["id" => "example-test", "hostname" => "example.test", "base_path" => "$h/domains/example.test", "environment" => "production", "branch" => "main", "keep_releases" => 5] + $common,
]];
file_put_contents(getenv("D") . "/sites.json", json_encode($sites, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents(getenv("D") . "/credentials.json", json_encode(["default" => "test_token_0123456789abcdef", "sites" => []]));
'

TB=$HOME_DIR/domains/example.test/apps/test
PB=$HOME_DIR/domains/example.test
TSTATE=$DATA/state/test-example-test.json
PSTATE=$DATA/state/example-test.json
releases() { ls "$1/releases" 2>/dev/null | wc -l | tr -d ' '; }
live() { cat "$1/current/public/index.php" 2>/dev/null || echo none; }
lastlog() { cat "$DATA/logs/$1/$(json "$2" history.0.id).log"; }

# ------------------------------------------------------------------ tests
section "First poll: testing site deploys the branch head, production waits for a release"
OUT=$(runner)
assert_contains "$OUT" '"activated":[{"site":"test-example-test","php":"default"}]' "runner reports the activation as JSON"
assert_eq "$(json "$TSTATE" history.0.status)" success "deployment succeeded"
assert_eq "$(live "$TB")" "<?php echo 'version 1';" "current serves version 1"
assert_eq "$(readlink "$TB/current")" "releases/$(json "$TSTATE" history.0.release)" "current is a relative symlink into releases/"
assert_eq "$(readlink "$TB/current/.env")" "../../shared/.env" ".env is linked to shared/"
assert_eq "$(cat "$TB/shared/.env")" "APP_NAME=Example" "shared/.env was created from .env.example"
assert_eq "$(readlink "$TB/current/storage")" "../../shared/storage" "storage is linked to shared/"
[ -f "$TB/shared/storage/logs/.gitignore" ] && pass "shared/storage was seeded from the repository" || fail "storage seed"
assert_eq "$(cat "$TB/current/REVISION")" "$SHA1" "REVISION contains the commit"
assert_eq "$(git -C "$TB/current" remote get-url origin)" "https://github.com/acme/app.git" "origin URL has no credentials"
LOG=$(lastlog test-example-test "$TSTATE")
assert_contains "$LOG" "building $SHA1 on [main] tag [] env testing manual=0" "deploy script received the DEPLOY_* variables"
assert_contains "$LOG" "forge release dir $TB/releases/" "Forge aliases are exported"
assert_contains "$LOG" "composer install --no-dev" "DEPLOY_COMPOSER works"
assert_contains "$LOG" "artisan queue:restart in $TB/releases/$(json "$TSTATE" history.0.release)" "\$RESTART_QUEUES() runs in the live release"
assert_not_contains "$LOG" "TOKEN LEAKED" "GitHub token is not exported to the deploy script"
assert_contains "$LOG" "Toolchain: PHP " "the deploy log lists PHP, Composer, Node.js and npm"
assert_eq "$(cat "$PB/public_html/index.html")" "DirectAdmin default page" "public_html is untouched"
assert_eq "$(releases "$PB")" 0 "production site has no release yet"
assert_eq "$(json "$PSTATE" remote)" null "production knows there is no release"
assert_eq "$(stat -c %a "$DATA")" 700 "~/.laravel-github-deploy is private"

section "No change on GitHub: nothing is deployed and the ETag is reused"
runner >/dev/null
assert_eq "$(json "$TSTATE" history | "$PHP" -r 'echo count(json_decode(stream_get_contents(STDIN), true));')" 1 "no second deployment"
assert_contains "$(tail -n 2 "$T/fake/requests.log")" "304 /repos/acme/app/commits" "conditional request answered with 304"

section "New commit: deployed automatically"
SHA2=$(commit "Second version" 2)
runner >/dev/null
assert_eq "$(live "$TB")" "<?php echo 'version 2';" "current serves version 2"
assert_eq "$(releases "$TB")" 2 "two releases on disk"
assert_eq "$(json "$TSTATE" history.0.message)" "Second version" "commit message recorded"

section "Failing build: release discarded, site keeps running, no retry loop"
touch "$WORK/FAIL_BUILD"
SHA3=$(commit "Broken build" 3)
runner >/dev/null
assert_eq "$(json "$TSTATE" history.0.status)" failed "deployment marked failed"
assert_contains "$(json "$TSTATE" history.0.error)" "exit code 3" "exit code reported"
assert_eq "$(live "$TB")" "<?php echo 'version 2';" "version 2 is still live"
assert_eq "$(releases "$TB")" 2 "failed release was removed"
assert_contains "$(lastlog test-example-test "$TSTATE")" "Removed unfinished release" "log explains the cleanup"
runner >/dev/null
assert_eq "$(json "$TSTATE" history.0.target_id)" "$SHA3" "failed commit is not retried automatically"
assert_eq "$(json "$TSTATE" history | "$PHP" -r 'echo count(json_decode(stream_get_contents(STDIN), true));')" 3 "still three history entries"

section "Fix pushed: deploys again; old releases are cleaned up (keep 3)"
rm "$WORK/FAIL_BUILD"
commit "Fixed" 4 >/dev/null
runner >/dev/null
commit "Five" 5 >/dev/null
runner >/dev/null
SHA6=$(commit "Six" 6)
runner >/dev/null
assert_eq "$(live "$TB")" "<?php echo 'version 6';" "version 6 is live"
assert_eq "$(releases "$TB")" 3 "only 3 releases kept"

section "Production: deploys the latest published release only"
git -C "$WORK" tag v1.0.0 "$SHA2"
git -C "$WORK" tag v2.0.0-rc1 "$SHA6"
git -C "$WORK" push --quiet origin --tags 2>/dev/null
cat > "$T/fake/releases.json" <<JSON
[{"tag_name":"v1.0.0","name":"First release"},
 {"tag_name":"v2.0.0-rc1","prerelease":true},
 {"tag_name":"v3.0.0","draft":true}]
JSON
runner >/dev/null
assert_eq "$(json "$PSTATE" history.0.status)" success "release deployed"
assert_eq "$(json "$PSTATE" history.0.label)" v1.0.0 "v1.0.0 deployed (pre-release and draft ignored)"
assert_eq "$(live "$PB")" "<?php echo 'version 2';" "production serves the tagged commit"
assert_contains "$(lastlog example-test "$PSTATE")" "on [] tag [v1.0.0] env production" "DEPLOY_TAG is set"
assert_eq "$(cat "$PB/public_html/index.html")" "DirectAdmin default page" "public_html is still untouched"
assert_eq "$(releases "$TB")" 3 "testing site in apps/test is unaffected"

section "Rollback: previous release goes live and is not redeployed"
PREV=$(ls "$TB/releases" | sort | tail -n 2 | head -n 1)
echo "{\"action\":\"rollback\",\"release\":\"$PREV\"}" > "$DATA/queue/test-example-test.json"
runner >/dev/null
assert_eq "$(readlink "$TB/current")" "releases/$PREV" "current points to the previous release"
assert_eq "$(json "$TSTATE" history.0.type)" rollback "rollback recorded"
runner >/dev/null
assert_eq "$(json "$TSTATE" history.0.type)" rollback "next poll does not redeploy the branch head"

section "Deploy a chosen commit"
echo "{\"action\":\"deploy_ref\",\"ref\":\"${SHA1:0:10}\"}" > "$DATA/queue/test-example-test.json"
runner >/dev/null
assert_eq "$(live "$TB")" "<?php echo 'version 1';" "older commit deployed on request"
assert_contains "$(lastlog test-example-test "$TSTATE")" "manual=1" "DEPLOY_MANUAL=1"
[ ! -f "$DATA/queue/test-example-test.json" ] && pass "queue file consumed" || fail "queue file left behind"

section "Manual deploy and auto-deploy off"
json "$DATA/sites.json" sites.example-test.auto_deploy --set false
echo '[{"tag_name":"v1.0.0"},{"tag_name":"v1.1.0","name":"Minor"}]' > "$T/fake/releases.json"
git -C "$WORK" tag v1.1.0 "$SHA6" && git -C "$WORK" push --quiet origin --tags 2>/dev/null
runner >/dev/null
assert_eq "$(json "$PSTATE" history.0.label)" v1.0.0 "auto-deploy off: new release not deployed"
assert_eq "$(json "$PSTATE" remote.label)" v1.1.0 "but it is shown as available"
echo '{"action":"deploy"}' > "$DATA/queue/example-test.json"
runner >/dev/null
assert_eq "$(json "$PSTATE" history.0.label)" v1.1.0 "Deploy now deploys the latest release"
assert_eq "$(live "$PB")" "<?php echo 'version 6';" "production serves v1.1.0"

section "Concurrent runs never deploy the same site twice"
touch "$WORK/SLOW_BUILD"
commit "Slow" 7 >/dev/null
BEFORE=$(releases "$TB")
runner --site=test-example-test >/dev/null &
FIRST=$!
sleep 1
runner --site=test-example-test >/dev/null
wait "$FIRST"
assert_eq "$(releases "$TB")" "$BEFORE" "keep-count unchanged (3)"
assert_eq "$(json "$TSTATE" history | "$PHP" -r '$h=json_decode(stream_get_contents(STDIN),true); echo count(array_filter($h, fn($e)=>($e["commit_message"]??"")==="Slow"));')" 1 "exactly one deployment of the new commit"
rm "$WORK/SLOW_BUILD"

section "Interrupted deployment is recovered"
mkdir -p "$TB/releases/20200101000000/public"
json "$TSTATE" running --set '{"id":"20200101000000","started_at":1}'
HIST=$(json "$TSTATE" history)
json "$TSTATE" history --set "[{\"id\":\"20200101000000\",\"type\":\"deploy\",\"release\":\"20200101000000\",\"status\":\"running\",\"label\":\"x\",\"started_at\":1},${HIST:1}"
runner >/dev/null
assert_eq "$(json "$TSTATE" running)" null "running flag cleared"
assert_eq "$(json "$TSTATE" history | "$PHP" -r '$h=json_decode(stream_get_contents(STDIN),true); foreach($h as $e) if($e["id"]==="20200101000000") echo $e["status"];')" failed "interrupted entry marked failed"
[ ! -d "$TB/releases/20200101000000" ] && pass "unfinished release removed" || fail "unfinished release still on disk"

section "Root dispatcher → worker → runner chain (test mode, no runuser)"
mkdir -p "$T/da-users/$ME"
echo "suspended=no" > "$T/da-users/$ME/user.conf"
export GIT_DEPLOY_DA_USERS_DIR=$T/da-users
commit "Eight" 8 >/dev/null
"$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/bin/dispatcher.php" --foreground
assert_eq "$(live "$TB")" "<?php echo 'version 7';" "not deployed before the poll interval has passed"
json "$TSTATE" last_poll --set "$(( $(date +%s) - 300 ))"
"$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/bin/dispatcher.php" --foreground
STATUS=$T/plugin-data/status/$ME.json
assert_eq "$(json "$STATUS" sites.0.hostname)" example.test "status file lists the sites for the admin overview"
assert_contains "$(json "$STATUS" fpm)" "php-fpm" "PHP-FPM reload requested after activation"
assert_eq "$(live "$TB")" "<?php echo 'version 8';" "commit deployed through the dispatcher"
assert_eq "$(json "$T/plugin-data/status/_dispatcher.json" eligible_users)" 1 "dispatcher found the user"
echo "suspended=yes" > "$T/da-users/$ME/user.conf"
"$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/bin/dispatcher.php" --foreground
assert_eq "$(json "$T/plugin-data/status/_dispatcher.json" eligible_users)" 0 "suspended users are skipped"

section "Frontend build: Node.js and npm are required when the release has a package.json"
mkdir -p "$HOME_DIR/node/bin" "$HOME_DIR/old-node/bin" "$HOME_DIR/.nvm"
printf '#!/bin/sh\necho v22.11.0\n' > "$HOME_DIR/node/bin/node"
printf '#!/bin/sh\necho v20.11.1\n' > "$HOME_DIR/old-node/bin/node"
printf '#!/bin/sh\nif [ "$1" = --version ]; then echo 10.9.0; else echo "fake npm $*"; fi\n' > "$HOME_DIR/node/bin/npm"
cp "$HOME_DIR/node/bin/npm" "$HOME_DIR/old-node/bin/npm"
chmod 755 "$HOME_DIR"/node/bin/* "$HOME_DIR"/old-node/bin/*
touch "$HOME_DIR/.nvm/nvm.sh"
echo '{"private":true}' > "$WORK/package.json"
touch "$WORK/NO_NODE"
BEFORE=$(releases "$TB")
commit "Needs Node.js" 9 >/dev/null
runner --site=test-example-test >/dev/null
assert_eq "$(json "$TSTATE" history.0.status)" failed "deployment stops when node and npm are missing"
assert_contains "$(json "$TSTATE" history.0.error)" "exit code 1: Node.js 22 or newer and npm are needed to build this release, but node and npm were not found on PATH (/nonexistent)" "the history says why"
LOG=$(lastlog test-example-test "$TSTATE")
assert_contains "$LOG" 'nvm is installed in ~/.nvm, but without any Node.js version. Run this once over SSH: nvm install 22' "the log points at the empty nvm installation"
assert_not_contains "$LOG" "fake npm" "npm is not run"
assert_eq "$(live "$TB")" "<?php echo 'version 8';" "the previous release stays live"
assert_eq "$(releases "$TB")" "$BEFORE" "the unfinished release was removed"
rm "$WORK/NO_NODE"
touch "$WORK/OLD_NODE"
commit "Node.js too old" 10 >/dev/null
runner --site=test-example-test >/dev/null
assert_eq "$(json "$TSTATE" history.0.status)" failed "deployment stops when Node.js is older than 22"
assert_contains "$(json "$TSTATE" history.0.error)" "Node.js 22 or newer is needed to build this release, but $HOME_DIR/old-node/bin/node is version 20.11.1" "the history names the old version"
assert_not_contains "$(lastlog test-example-test "$TSTATE")" "fake npm" "npm is not run with an old Node.js"
assert_eq "$(live "$TB")" "<?php echo 'version 8';" "the previous release is still live"
rm "$WORK/OLD_NODE"
commit "Node.js available" 11 >/dev/null
runner --site=test-example-test >/dev/null
assert_eq "$(json "$TSTATE" history.0.status)" success "deploys once Node.js 22 and npm are on PATH"
LOG=$(lastlog test-example-test "$TSTATE")
assert_contains "$LOG" "==> Node.js 22.11.0 ($HOME_DIR/node/bin/node), npm 10.9.0" "\$REQUIRE_NODE() logs the versions it found"
assert_contains "$LOG" "fake npm ci" "npm ci runs"
assert_contains "$LOG" "fake npm run build" "npm run build runs"
assert_eq "$(live "$TB")" "<?php echo 'version 11';" "the new release is live"

section "Node.js installed with nvm is found on the deploy PATH, whatever versions are installed"
for v in 20.11.1 22.11.0 24.18.0; do
    mkdir -p "$HOME_DIR/.nvm/versions/node/v$v/bin"
    printf '#!/bin/sh\necho v%s\n' "$v" > "$HOME_DIR/.nvm/versions/node/v$v/bin/node"
    printf '#!/bin/sh\nif [ "$1" = --version ]; then echo 10.9.0; else echo "nvm npm $*"; fi\n' > "$HOME_DIR/.nvm/versions/node/v$v/bin/npm"
    chmod 755 "$HOME_DIR/.nvm/versions/node/v$v/bin/"*
done
mkdir -p "$HOME_DIR/.nvm/alias" && echo 22 > "$HOME_DIR/.nvm/alias/default"
touch "$WORK/KEEP_PATH"
commit "Node.js from nvm" 12 >/dev/null
runner --site=test-example-test >/dev/null
assert_eq "$(json "$TSTATE" history.0.status)" success "deploys with nvm's Node.js without changes to the script"
LOG=$(lastlog test-example-test "$TSTATE")
assert_contains "$LOG" "==> Node.js 22.11.0 ($HOME_DIR/.nvm/versions/node/v22.11.0/bin/node), npm 10.9.0" "nvm's default version (22) is used, not the newest (24) or the oldest (20)"
assert_contains "$LOG" "nvm npm ci" "npm comes from the same nvm version"
assert_contains "$LOG" "Toolchain: PHP " "the toolchain line is logged"
assert_contains "$LOG" "Node.js 22.11.0, npm 10.9.0" "and shows nvm's Node.js"
echo "suspended=no" > "$T/da-users/$ME/user.conf"
"$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/bin/dispatcher.php" --foreground
assert_eq "$(json "$STATUS" nvm.version)" 22.11.0 "the runner reports the user's nvm Node.js for the admin overview"
assert_eq "$(json "$STATUS" nvm.npm)" true "and that npm is next to it"

section "User interface (JSON API)"
HTML=$(ui index)
assert_contains "$HTML" 'id="gd-boot"' "pages render the app shell"
assert_contains "$HTML" '"area":"user","base":"/CMD_PLUGINS/plugin","api":"/CMD_PLUGINS/plugin/api.raw"' "boot data points the app at the API"
assert_contains "$(ui site "site=test-example-test")" 'id="gd-app"' "every page returns the shell"
OUT=$(api overview)
assert_contains "$OUT" $'HTTP/1.1 200 OK\nContent-Type: application/json' "api.raw prints its own headers"
assert_contains "$OUT" '"hostname":"test.example.test"' "overview lists the testing site"
assert_contains "$OUT" '"hostname":"example.test"' "overview lists the production site"
assert_eq "$(jget "$OUT" ok)" true "responses carry ok:true"
OUT=$(api site "site=test-example-test")
assert_eq "$(jget "$OUT" site.docroot_path)" "$TB/current/public" "exact document root path is provided"
assert_contains "$OUT" '"current":false,"can_activate":true' "older releases can be rolled back"
assert_contains "$(api site "site=nope")" "HTTP/1.1 404" "unknown sites are a 404"
OUT=$(api site_form "site=test-example-test")
assert_contains "$(jget "$OUT" node.path)" "$HOME_DIR/.local/bin:$HOME_DIR/bin:$HOME_DIR/.nvm/versions/node/v22.11.0/bin:/usr/local/bin" "the site form checks Node.js on the deploy PATH, nvm's included"
assert_eq "$(jget "$OUT" node.node.path)" "$HOME_DIR/.nvm/versions/node/v22.11.0/bin/node" "the site form finds nvm's Node.js"
assert_eq "$(jget "$OUT" outdated_script)" false "a custom script is not mistaken for the previous default"
assert_eq "$(jget "$OUT" node.min_major)" 22 "the site form knows the minimum Node.js version"
LOGID=$(json "$TSTATE" history.0.id)
OUT=$(api log "site=test-example-test&id=$LOGID")
assert_contains "$OUT" '"chunk":"[' "log returns the tail of the log"
OUT=$(api log "site=test-example-test&id=$LOGID&offset=$(jget "$OUT" offset)")
assert_eq "$(jget "$OUT" chunk)" "" "polling from the end of the log returns nothing new"
CSRF=$(jget "$(api csrf)" csrf)
[ -n "$CSRF" ] && pass "the API hands out a CSRF token" || fail "no csrf token"
OUT=$(api save_token "" '{"token":"test_token_0123456789abcdef"}')
assert_contains "$OUT" "GitHub token saved." "token saved after GitHub accepted it"
assert_eq "$(stat -c %a "$DATA/credentials.json")" 600 "credentials.json is private"
OUT=$(api save_token "" '{"token":"ghp_wrongwrongwrongwrongwrong"}')
assert_contains "$OUT" "HTTP/1.1 422" "invalid token is refused"
assert_contains "$OUT" "rejected the token" "the refusal explains why"
OUT=$(api set_auto "" '{"site":"test-example-test","enabled":false}' bad)
assert_contains "$OUT" '"code":"csrf"' "invalid CSRF token is rejected"
OUT=$(apiraw user "action=set_auto" "csrf=$CSRF&amp;payload=$(b64url '{"site":"test-example-test","enabled":false}')")
assert_contains "$OUT" "Automatic deployments paused" "toggle works (entity-encoded POST)"
assert_eq "$(json "$DATA/sites.json" sites.test-example-test.auto_deploy)" false "auto-deploy saved"
OUT=$(QUERY_STRING=action=set_auto POST= REQUEST_METHOD=POST USERNAME=$ME "$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/app/user.php" api)
assert_contains "$OUT" '"code":"no_post_data"' "a POST without data is explained, not mistaken for an expired session"
assert_contains "$(api set_auto)" '"code":"no_post_data"' "writes are refused over GET"
mkdir -p "$HOME_DIR/domains/other.test"
SCRIPT=$'$CREATE_RELEASE()\ncd $DEPLOY_RELEASE_DIRECTORY\necho "a & b < c" \'q\' %2B ü\n$ACTIVATE_RELEASE()\n'
site_payload() { # hostname repo branch [script]
    H="$1" R="$2" B="$3" S="${4:-}" "$PHP" -r 'echo json_encode(["site" => "", "hostname" => getenv("H"), "environment" => "testing", "repo" => getenv("R"), "branch" => getenv("B"), "auto_deploy" => true, "php" => "default", "web_dir" => "public", "shared_paths" => ".env", "keep_releases" => 4, "base_path" => "", "script" => getenv("S")]);'
}
OUT=$(api save_site "" "$(site_payload other.test https://github.com/acme/app.git main "$SCRIPT")")
assert_contains "$OUT" "Site created" "new site created through the API"
assert_eq "$(jget "$OUT" site.id)" other-test "the new site id is returned"
assert_eq "$(json "$DATA/sites.json" sites.other-test.base_path)" "$HOME_DIR/domains/other.test" "default base path"
assert_eq "$(json "$DATA/sites.json" sites.other-test.repo)" "acme/app" "repository URL normalised"
assert_eq "$(json "$DATA/sites.json" sites.other-test.script)" "${SCRIPT%$'\n'}" "deploy script survived the round trip byte-for-byte"
OUT=$(api save_site "" "$(site_payload evil.test acme/missing main)")
assert_contains "$OUT" "HTTP/1.1 422" "validation errors are a 422"
assert_contains "$OUT" '"hostname":"This is not one of your domains' "foreign hostnames are refused with a field error"
OUT=$(api save_site "" "$(site_payload sub.other.test acme/missing main)")
assert_contains "$OUT" "was not found" "inaccessible repositories are refused"
OUT=$(api save_site "" "$(site_payload sub.other.test acme/app nope)")
assert_contains "$OUT" '"branch":"Branch nope was not found' "missing branches are refused on the branch field"
OUT=$(api check_repo "" '{"repo":"acme/app","environment":"testing","branch":"main"}')
assert_contains "$OUT" "acme/app is accessible. Branch main found" "the live repository check reports access"
OUT=$(api check_repo "" '{"repo":"acme/app","environment":"testing","branch":"nope"}')
assert_contains "$OUT" '"branch":"Branch nope was not found' "the live repository check reports a missing branch"
OUT=$(api deploy "" '{"site":"other-test"}')
assert_contains "$OUT" "Deployment queued" "Deploy now queues a request"
assert_eq "$(json "$DATA/queue/other-test.json" action)" deploy "queue file written"
OUT=$(api deploy "" '{"site":"other-test"}')
assert_contains "$OUT" "HTTP/1.1 409" "a second request while one is queued is a conflict"
assert_contains "$(api cancel_queue "" '{"site":"other-test"}')" '"cancelled":true' "a queued action can be cancelled"
assert_contains "$(api deploy "" '{"site":"other-test"}')" "Deployment queued" "and queued again"
OUT=$(api check_docroot "" '{"site":"test-example-test"}')
assert_contains "$OUT" "does not serve files from" "document root check reports a mismatch"
assert_eq "$(jget "$OUT" docroot.ok)" false "the mismatch is a result, not an error"
DPORT=$(( PORT + 1 ))
"$PHP" -S "127.0.0.1:$DPORT" -t "$TB/current/public" >/dev/null 2>&1 &
DOCROOT_PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$DPORT/" && break; sleep 0.1; done
OUT=$(GIT_DEPLOY_DOCROOT_URL=http://127.0.0.1:$DPORT api check_docroot "" '{"site":"test-example-test"}')
kill "$DOCROOT_PID" 2>/dev/null || true
assert_contains "$OUT" "test.example.test serves files from $TB/current/public" "document root check passes when the domain serves current/public"
assert_eq "$(json "$TSTATE" docroot.ok)" true "verification is remembered"
assert_eq "$(jget "$(api site "site=test-example-test")" docroot.ok)" true "the site reports the verified document root"
[ -z "$(ls "$TB/current/public" | grep laravel-github-deploy-check || true)" ] && pass "check file was removed" || fail "check file left behind"
OUT=$(api save_env "" "$(printf '{"site":"test-example-test","content":%s}' "$("$PHP" -r 'echo json_encode("APP_KEY=base64:abc+/=\r\nDB=x & ü");')")")
assert_eq "$(cat "$TB/shared/.env")" $'APP_KEY=base64:abc+/=\nDB=x & ü' ".env editor writes shared/.env byte-for-byte (line endings normalised)"
assert_eq "$(jget "$(api env "site=test-example-test")" content)" $'APP_KEY=base64:abc+/=\nDB=x & ü' ".env is read back unchanged"
BIG=$("$PHP" -r 'echo json_encode(["site" => "test-example-test", "content" => str_repeat("x", 70000)]);')
assert_contains "$(api save_env "" "$BIG")" "too large" "oversized .env files are refused"
OUT=$(api delete_site "" '{"site":"other-test","confirm":"wrong"}')
assert_contains "$OUT" '"confirm":"Type other.test to confirm."' "removing a site requires typing its name"
mkdir -p "$T/plugin-data/access" && echo deny > "$T/plugin-data/access/$ME"
assert_contains "$(ui index)" "not enabled for your account" "admin deny override blocks the UI"
assert_contains "$(api overview)" '"code":"disabled"' "and the API"
rm "$T/plugin-data/access/$ME"

section "Base path with a real 'current' directory"
mkdir -p "$HOME_DIR/domains/other.test/current"
runner --site=other-test >/dev/null
assert_eq "$(json "$DATA/state/other-test.json" history.0.status)" failed "deployment refused"
assert_contains "$(json "$DATA/state/other-test.json" history.0.error)" "is not a symlink" "clear error shown in the history"
[ -d "$HOME_DIR/domains/other.test/current" ] && pass "existing directory left untouched" || fail "current directory was removed"

section "Installed under a different folder name"
ALT=$T/plugins/laravel_github_deploy-0.1.0
mkdir -p "$T/plugins" && cp -r "$PLUGIN" "$ALT"
sed -i "s#/usr/local/bin/php#$PHP#" "$ALT"/user/* "$ALT"/admin/*
chmod 755 "$ALT"/user/* "$ALT"/admin/* # as install.sh does; the checkout may not carry the exec bits
HTML=$(cd / && QUERY_STRING= POST= USERNAME=$ME "$ALT/user/index.html")
assert_contains "$HTML" '"base":"/CMD_PLUGINS/laravel_github_deploy-0.1.0"' "user pages know the actual folder name"
assert_contains "$HTML" '"api":"/CMD_PLUGINS/laravel_github_deploy-0.1.0/api.raw"' "the app calls the API in the actual folder"
if [ -f "$PLUGIN/images/app.js" ]; then
    assert_contains "$HTML" '/CMD_PLUGINS/laravel_github_deploy-0.1.0/images/app.js?v=' "assets load from the actual folder name, with a cache-busting hash"
fi
OUT=$(cd / && QUERY_STRING=action=overview POST= USERNAME=$ME "$ALT/user/api.raw")
assert_contains "$OUT" $'HTTP/1.1 200 OK\nContent-Type: application/json' "user/api.raw works through its wrapper"
HTML=$(cd /tmp && QUERY_STRING= POST= "$ALT/admin/index.html")
assert_contains "$HTML" '"api":"/CMD_PLUGINS_ADMIN/laravel_github_deploy-0.1.0/api.raw"' "admin app calls the API in the actual folder"

section "Admin interface (JSON API)"
HTML=$(QUERY_STRING= POST= "$PHP" -c "$PLUGIN/php.ini" "$PLUGIN/app/admin.php" index)
assert_contains "$HTML" '"area":"admin"' "admin page renders the app shell"
OUT=$(adminapi overview)
assert_contains "$OUT" '"hostname":"test.example.test"' "overview lists user sites"
assert_contains "$OUT" '"label":"Plugin version"' "diagnostics are included"
assert_contains "$OUT" '"label":"Node.js"' "diagnostics report Node.js"
assert_contains "$OUT" '"label":"npm"' "diagnostics report npm"
assert_contains "$OUT" "$ME 22.11.0" "the Node.js row names the Node.js the user installed with nvm, which the admin account cannot see"
assert_contains "$OUT" '"last_run":' "cron status is included"
ACSRF=$(jget "$(adminapi csrf)" csrf)
OUT=$(adminapi save_settings '{"poll_interval":0}')
assert_contains "$OUT" '"poll_interval":"Must be a number between 1 and 60."' "invalid settings are refused per field"
OUT=$(adminapi save_settings '{"poll_interval":7,"reload_fpm":false}')
assert_contains "$OUT" "Settings saved" "settings saved"
assert_eq "$(json "$T/plugin-data/settings.json" poll_interval)" 7 "poll interval stored"
assert_eq "$(json "$T/plugin-data/settings.json" reload_fpm)" false "checkbox stored"
assert_contains "$(adminapi set_access '{"user":"nobody-here","value":"allow"}')" "There is no DirectAdmin user nobody-here" "unknown users are refused"
OUT=$(adminapi set_access "{\"user\":\"$ME\",\"value\":\"deny\"}")
assert_contains "$OUT" "can no longer use" "access can be denied"
assert_contains "$(api overview)" '"code":"disabled"' "the denied user is blocked"
assert_contains "$(adminapi remove_access "{\"user\":\"$ME\"}")" "Override for $ME removed" "override removed"
assert_contains "$(api overview)" '"ok":true' "the user can use the plugin again"
OUT=$(adminapi overview)
assert_contains "$OUT" '"permission_fix":null' "a writable data directory needs no fix"
assert_contains "$OUT" '"status_readable":true' "the admin account can read the status files"
if [ "$(id -u)" != 0 ]; then # root ignores the mode
    chmod 555 "$T/plugin-data"
    OUT=$(adminapi overview)
    assert_contains "$OUT" '"writable":false' "an unwritable data directory is reported"
    assert_contains "$OUT" '"permission_fix":"install"' "with install.sh as the fix when it belongs to the admin account"
    assert_contains "$OUT" 'NOT writable: owned by' "and in the diagnostics"
    OUT=$(adminapi save_settings '{"poll_interval":9}')
    assert_contains "$OUT" '"code":"not_writable"' "settings are refused while it is not writable"
    assert_contains "$OUT" "scripts/install.sh as root" "the refusal names the fix"
    assert_eq "$(json "$T/plugin-data/settings.json" poll_interval)" 7 "nothing was saved"
    chmod 755 "$T/plugin-data"
fi

section "Admin override for Node.js and npm"
mkdir -p "$T/admin-node/bin"
printf '#!/bin/sh\necho v20.11.1\n' > "$T/admin-node/bin/node-20"
printf '#!/bin/sh\necho v22.5.0\n' > "$T/admin-node/bin/node-22"
printf '#!/bin/sh\nif [ "$1" = --version ]; then echo 10.8.0; else echo "admin npm $*"; fi\n' > "$T/admin-node/bin/npm-22"
chmod 755 "$T"/admin-node/bin/*
assert_contains "$(adminapi save_settings '{"node_path":"bin/node-22"}')" '"node_path":"Enter an absolute path' "a relative Node.js path is refused"
OUT=$(adminapi save_settings "{\"node_path\":\"$T/admin-node/bin/node-20\"}")
assert_contains "$OUT" "HTTP/1.1 422" "a Node.js older than 22 is refused when saving"
assert_contains "$OUT" "This is Node.js 20.11.1; deploy scripts need 22 or newer." "the refusal names the version found"
assert_eq "$(json "$T/plugin-data/settings.json" node_path)" "" "nothing was saved"
OUT=$(adminapi save_settings "{\"node_path\":\"$T/admin-node/bin/node-22\"}")
assert_contains "$OUT" "Deploy scripts now use Node.js 22.5.0 ($T/admin-node/bin/node-22) and npm 10.8.0 ($T/admin-node/bin/npm-22)." "the override is run and confirmed (npm-22 found next to node-22)"
assert_eq "$(json "$T/plugin-data/settings.json" node_path)" "$T/admin-node/bin/node-22" "the Node.js path is stored"
assert_contains "$(adminapi overview)" "22.5.0 ($T/admin-node/bin/node-22), set below" "diagnostics show the configured Node.js"
OUT=$(api site_form "site=test-example-test")
assert_eq "$(jget "$OUT" node.node.version)" 22.5.0 "the site form sees the administrator's Node.js"
assert_eq "$(jget "$OUT" node.admin.node)" "$T/admin-node/bin/node-22" "and who set it"
commit "Admin Node.js" 13 >/dev/null
echo '{"action":"deploy"}' > "$DATA/queue/test-example-test.json"
runner --site=test-example-test >/dev/null
assert_eq "$(json "$TSTATE" history.0.status)" success "deploys with the administrator's Node.js"
LOG=$(lastlog test-example-test "$TSTATE")
assert_contains "$LOG" "Set by the administrator: node → $T/admin-node/bin/node-22, npm → $T/admin-node/bin/npm-22" "the log says the administrator set them"
assert_contains "$LOG" "Node.js 22.5.0, npm 10.8.0" "the toolchain line shows the override"
assert_contains "$LOG" "==> Node.js 22.5.0 ($T/admin-node/bin/node-22), npm 10.8.0" "\$REQUIRE_NODE() uses it, ahead of the user's nvm"
assert_contains "$LOG" "admin npm ci" "npm ci runs the administrator's npm"
assert_contains "$(adminapi save_settings '{"node_path":"","npm_path":""}')" "Deploy scripts find Node.js and npm on PATH again." "clearing the override is confirmed"
api site_form "site=test-example-test" >/dev/null
[ ! -L "$DATA/node-bin/node" ] && [ ! -L "$DATA/node-bin/npm" ] && pass "the user's node-bin links are removed with the override" || fail "node-bin links left behind"

printf '\n\033[32mAll %d checks passed.\033[0m\n' "$PASS"
