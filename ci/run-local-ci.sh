#!/usr/bin/env bash
#
# Runs the same checks as .github/workflows/ci.yml, locally, without pushing.
# Requires: PHP 8.2+, Composer, Docker, Node 22, and a JRE (the mustache lint
# step validates rendered HTML with vnu.jar).
#
# Usage: ci/run-local-ci.sh [--with-behat] [--reinstall-ci]

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

WITH_BEHAT=false
REINSTALL_CI=false
for arg in "$@"; do
    case "$arg" in
        --with-behat) WITH_BEHAT=true ;;
        --reinstall-ci) REINSTALL_CI=true ;;
    esac
done

DB_CONTAINER=coursesync-ci-db
DB_PORT=${MOODLE_PLUGIN_CI_DB_PORT:-5433}
MOODLE_BRANCH=${MOODLE_BRANCH:-MOODLE_501_STABLE}

# moodle-plugin-ci's own bundled binaries (phpcs, phpmd, ...) are located via
# paths hardcoded relative to its OWN package directory, which only resolve
# correctly when it is the composer project root — i.e. installed via
# `composer create-project`, not required as a dev dependency of another
# project (confirmed by testing: phpcs fails to find its own vendored
# squizlabs/php_codesniffer binary when nested that way). So, matching both
# moodle-plugin-ci's own docs and momopda's convention, it lives as a
# persistent sibling install next to this plugin, not inside it.
CI_DIR="$PLUGIN_DIR/../moodle-plugin-ci"
CI_BIN="$CI_DIR/bin/moodle-plugin-ci"

# The scratch Moodle checkout that `install` clones must also live outside
# the plugin directory, or it nests inside itself when copied into the
# scratch site's blocks/coursesync.
SCRATCH_DIR="$PLUGIN_DIR/../ci-scratch"
mkdir -p "$SCRATCH_DIR"

cleanup() {
    docker rm -f "$DB_CONTAINER" > /dev/null 2>&1 || true
}
trap cleanup EXIT

if [[ "$REINSTALL_CI" == true ]]; then
    rm -rf "$CI_DIR"
fi

if [[ ! -x "$CI_BIN" ]]; then
    echo "==> Installing moodle-plugin-ci (sibling install at $CI_DIR)"
    composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci "$CI_DIR" ^4
else
    echo "==> moodle-plugin-ci already installed at $CI_DIR (pass --reinstall-ci to refresh)"
fi

echo "==> Starting a throwaway Postgres container for the CI install"
docker run -d --name "$DB_CONTAINER" \
    -e POSTGRES_USER=postgres \
    -e POSTGRES_HOST_AUTH_METHOD=trust \
    -p "127.0.0.1:${DB_PORT}:5432" \
    postgres:17 > /dev/null

echo "==> Waiting for Postgres to accept connections"
until docker exec "$DB_CONTAINER" pg_isready -U postgres > /dev/null 2>&1; do
    sleep 1
done

cd "$SCRATCH_DIR"

echo "==> moodle-plugin-ci install (clones Moodle $MOODLE_BRANCH, sets up the test site)"
DB=pgsql MOODLE_BRANCH="$MOODLE_BRANCH" \
    "$CI_BIN" install --plugin "$PLUGIN_DIR" --db-host=127.0.0.1 --db-port="$DB_PORT"

echo "==> phplint";     "$CI_BIN" phplint
echo "==> phpcs";       "$CI_BIN" phpcs --max-warnings 0
echo "==> phpmd";       "$CI_BIN" phpmd || true
echo "==> phpdoc";      "$CI_BIN" phpdoc --max-warnings 0
echo "==> validate";    "$CI_BIN" validate
echo "==> savepoints";  "$CI_BIN" savepoints
echo "==> mustache";    "$CI_BIN" mustache
echo "==> grunt";       "$CI_BIN" grunt --max-lint-warnings 0
echo "==> phpunit";     "$CI_BIN" phpunit

if [[ "$WITH_BEHAT" == true ]]; then
    # --start-servers pulls a Selenium image even though every scenario in this
    # plugin is non-JavaScript. The default profile uses Firefox, whose image
    # has arm64 builds; --profile chrome (what GitHub Actions uses, on amd64)
    # would try to pull an amd64-only image here.
    echo "==> behat (--start-servers)"
    "$CI_BIN" behat --start-servers
else
    echo "==> behat skipped (pass --with-behat to run it; needs a Selenium Docker image)"
fi

echo "==> All checks complete."
