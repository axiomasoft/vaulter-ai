#!/usr/bin/env bash
# Builds the sibling Node artifacts that the PHP test suites execute.
#
# vaulter-ai's sheets tests run the real spreadsheet runtime and reopen a committed
# workbook in headless Chromium, so `composer test` needs, next to this checkout:
#   ../vaulter/packages/vue/dist                     (@vaulter/vue, a dependency of sheets-vue)
#   ../vaulter-sheets/packages/runtime/dist/cli.js   (spreadsheet runtime CLI)
#   ../vaulter-sheets/packages/realtime/dist         (imported by sheets-vue)
#   ../vaulter-sheets/packages/vue/dist              (VaulterSpreadsheet component)
#   Playwright Chromium                              (cold-reopen browser test)
#
# Every step is skipped while its output is newer than its sources, so running this
# before each `composer test` costs well under a second once everything is built.
#
# Environment:
#   VAULTER_SIBLINGS_ROOT      directory holding the sibling checkouts (default: parent of this repo)
#   VAULTER_PLAYWRIGHT_DEPS=1  also install Chromium's system packages (needs root; CI images)
#   VAULTER_SKIP_PREPARE=1     do nothing (the caller already prepared the siblings)
set -euo pipefail

if [[ "${VAULTER_SKIP_PREPARE:-0}" == "1" ]]; then
    exit 0
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
siblings="${VAULTER_SIBLINGS_ROOT:-$(dirname "$repo_root")}"
vaulter="$siblings/vaulter"
sheets="$siblings/vaulter-sheets"

log() { printf 'prepare-siblings: %s\n' "$*" >&2; }

require_dir() {
    if [[ ! -d "$1" ]]; then
        log "missing sibling checkout $1 (clone axiomasoft/$(basename "$1") next to vaulter-ai)"
        exit 1
    fi
}

require_node() {
    if ! command -v node >/dev/null 2>&1; then
        log "node is not on PATH; Node.js >=26.13.1 is required (see package.json engines)"
        exit 1
    fi
    local major minor
    IFS=. read -r major minor _ <<<"$(node -p 'process.versions.node')"
    if (( major < 26 || (major == 26 && minor < 13) )); then
        log "Node.js $(node -p 'process.versions.node') found; >=26.13.1 is required"
        exit 1
    fi
}

# stale OUTPUT SOURCE... : true when OUTPUT is missing or any SOURCE file is newer.
stale() {
    local output="$1"
    shift
    [[ -e "$output" ]] || return 0
    [[ -n "$(find "$@" -newer "$output" -type f -print -quit 2>/dev/null)" ]]
}

# npm ci only when node_modules is missing or older than the lockfile.
install_node_modules() {
    local dir="$1"
    if stale "$dir/node_modules/.package-lock.json" "$dir/package-lock.json"; then
        log "npm ci in $dir"
        (cd "$dir" && npm ci --no-audit --no-fund)
    fi
}

require_dir "$vaulter"
require_dir "$sheets"
require_node

install_node_modules "$vaulter"
if stale "$vaulter/packages/vue/dist/vaulter-vue.js" "$vaulter/packages/vue/src" "$vaulter/packages/vue/package.json"; then
    log "building @vaulter/vue"
    (cd "$vaulter/packages/vue" && npm run build)
fi

install_node_modules "$sheets"
if stale "$sheets/packages/runtime/dist/cli.js" "$sheets/packages/runtime/src" "$sheets/packages/runtime/package.json"; then
    log "building @vaulter/sheets-runtime"
    (cd "$sheets" && npm run build --workspace @vaulter/sheets-runtime)
fi
if stale "$sheets/packages/realtime/dist/native-adapter.js" "$sheets/packages/realtime/src" "$sheets/packages/realtime/package.json"; then
    log "building @vaulter/sheets-realtime"
    (cd "$sheets" && npm run build --workspace @vaulter/sheets-realtime)
fi
if stale "$sheets/packages/vue/dist/vaulter-sheets-vue.js" "$sheets/packages/vue/src" "$sheets/packages/vue/package.json" \
    "$vaulter/packages/vue/dist/vaulter-vue.js" "$sheets/packages/realtime/dist/native-adapter.js"; then
    log "building @vaulter/sheets-vue"
    (cd "$sheets" && npm run build --workspace @vaulter/sheets-vue)
fi

# Playwright skips browsers that are already installed, so this is cheap after the first run.
if [[ "${VAULTER_PLAYWRIGHT_DEPS:-0}" == "1" ]]; then
    (cd "$sheets" && npx playwright install --with-deps chromium)
else
    (cd "$sheets" && npx playwright install chromium) >/dev/null
fi
