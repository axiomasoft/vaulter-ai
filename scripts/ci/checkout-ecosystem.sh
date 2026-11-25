#!/usr/bin/env bash
# Provide the sibling repositories the root composer.json path repositories point to:
#   ../vaulter ../vaulter-sheets ../vaulter-collaboration   axiomasoft/*, branch main
#   ../agentools                                            axiomasoft/agentools, branch main
#   ../packman                                              axiomasoft/packman, the tag pinned in composer.json
# A sibling that already exists is reused, so the script is safe on self-hosted runners and
# in local clones. VAULTER_READ_TOKEN, when set, authenticates the fetches of the private
# repositories; it is passed as a header and never written into a remote URL or .git/config.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
workspace=$(dirname "$root")
packman_version=$(jq -er '.repositories[] | select(.url == "../packman/packages/*") | .options.versions["axiomasoft/packman"]' "$root/composer.json")

auth=()
if [[ -n "${VAULTER_READ_TOKEN:-}" ]]; then
    auth=(-c "http.https://github.com/.extraheader=Authorization: Basic $(printf 'x-access-token:%s' "$VAULTER_READ_TOKEN" | base64 -w0)")
fi

fetch_dependency() {
    local directory=$1 repository=$2 ref=$3
    local dest="$workspace/$directory"
    if [[ -e "$dest" ]]; then
        echo "checkout-ecosystem: reusing $dest" >&2
        return 0
    fi
    mkdir -p "$dest"
    git -C "$dest" init -q
    GIT_TERMINAL_PROMPT=0 git "${auth[@]}" -C "$dest" fetch --quiet --depth=1 "https://github.com/axiomasoft/$repository.git" "$ref"
    git -C "$dest" checkout -q -b main FETCH_HEAD
}

fetch_dependency vaulter vaulter refs/heads/main
fetch_dependency vaulter-sheets vaulter-sheets refs/heads/main
fetch_dependency vaulter-collaboration vaulter-collaboration refs/heads/main
fetch_dependency agentools agentools refs/heads/main
fetch_dependency packman packman "refs/tags/v$packman_version"
