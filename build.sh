#!/bin/sh
# Build the plugin tarball for DirectAdmin's Plugin Manager: dist/laravel_github_deploy.tar.gz
# Needs Node.js 22+ for the interface (ui/ → plugin/images/app.js and app.css). Set
# SKIP_UI_BUILD=1 to package interface files that were already built.
set -eu
cd "$(dirname "$0")"

VERSION=$(sed -n 's/^version=//p' plugin/plugin.conf)

MIN_NODE=22
if [ "${SKIP_UI_BUILD:-0}" != 1 ]; then
    if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
        echo "Node.js $MIN_NODE or newer with npm is needed to build the interface in ui/. Install it," >&2
        echo "or set SKIP_UI_BUILD=1 to package the files already in plugin/images/." >&2
        exit 1
    fi
    NODE_MAJOR=$(node -p 'process.versions.node.split(".")[0]')
    if [ "$NODE_MAJOR" -lt "$MIN_NODE" ]; then
        echo "Node.js $MIN_NODE or newer is needed to build the interface in ui/ (found $(node --version))." >&2
        echo "Upgrade it, or set SKIP_UI_BUILD=1 to package the files already in plugin/images/." >&2
        exit 1
    fi
    echo "Building the interface…"
    (cd ui && npm ci --no-audit --no-fund --loglevel=error && npm run build --silent)
fi
for f in plugin/images/app.js plugin/images/app.css; do
    if [ ! -s "$f" ]; then
        echo "$f is missing. Build the interface first: (cd ui && npm ci && npm run build)" >&2
        exit 1
    fi
done

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

# Copy everything except runtime data and local artefacts.
tar -C plugin --exclude='./data' --exclude='./bin/composer.phar' --exclude='*.swp' --exclude='*.map' -cf - . | tar -C "$STAGE" -xf -
# The plugin reads its version from here: DirectAdmin rewrites plugin.conf when it adds the
# plugin (diradmin, mode 0600), and admin pages cannot read that.
printf '%s\n' "$VERSION" > "$STAGE/VERSION"
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
chmod 755 "$STAGE"/user/* "$STAGE"/admin/* "$STAGE"/scripts/*.sh

# DirectAdmin may name the plugin folder (and its URLs) after the uploaded file, so the
# file name must be exactly <id>.tar.gz. The version lives in plugin.conf.
mkdir -p dist
rm -f dist/laravel_github_deploy*.tar.gz
tar -C "$STAGE" --owner=0 --group=0 --numeric-owner -czf dist/laravel_github_deploy.tar.gz .
echo "Built dist/laravel_github_deploy.tar.gz (version $VERSION)"
