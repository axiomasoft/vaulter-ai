#!/usr/bin/env bash
# Isolated Docker Compose consumer stand controller matching docs/testing-processes.md.
#
# Usage:
#   run_id=$(./stack.sh prepare <profile> /abs/path/to/artifacts.json)
#   ./stack.sh up <run-id>
#   ./stack.sh test <run-id> [sigkill-recovery|cold-reopen]
#   ./stack.sh restart <run-id> <service>
#   ./stack.sh down <run-id>
#   ./stack.sh clean <run-id>
set -euo pipefail

STAND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$STAND_DIR/../../.." && pwd)"
FIXTURE_ROOT="$STAND_DIR/.fixture"
PROFILES_FILE="$STAND_DIR/profiles.json"
COMPOSE_FILE="$STAND_DIR/compose.yaml"

log() {
    printf '[stack] %s\n' "$*" >&2
}

die() {
    printf '[stack] ERROR: %s\n' "$*" >&2
    exit 1
}

require_run_dir() {
    local run_id="$1"
    [[ -n "$run_id" && "$run_id" =~ ^[a-z0-9][a-z0-9._-]+$ ]] || die "Invalid run-id [$run_id]"
    local run_dir="$FIXTURE_ROOT/$run_id"
    [[ -d "$run_dir" && -f "$run_dir/.env" ]] || die "Run directory not prepared: $run_dir"
    printf '%s' "$run_dir"
}

compose_for_run() {
    local run_dir="$1"
    shift
    local project_name image_postgres
    project_name="$(grep -E '^COMPOSE_PROJECT_NAME=' "$run_dir/.env" | head -n1 | cut -d= -f2-)"
    image_postgres="$(grep -E '^IMAGE_POSTGRES=' "$run_dir/.env" | head -n1 | cut -d= -f2-)"
    [[ -n "$project_name" && "$project_name" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "Invalid or missing COMPOSE_PROJECT_NAME in $run_dir/.env"
    [[ -n "$image_postgres" && "$image_postgres" =~ ^[A-Za-z0-9./:@_-]+$ ]] || die "Invalid or missing IMAGE_POSTGRES in $run_dir/.env"
    env -u COMPOSE_PROJECT_NAME -u IMAGE_POSTGRES \
        COMPOSE_PROJECT_NAME="$project_name" \
        IMAGE_POSTGRES="$image_postgres" \
        docker compose --project-name "$project_name" --project-directory "$STAND_DIR" -f "$COMPOSE_FILE" --env-file "$run_dir/.env" "$@"
}

cmd_prepare() {
    local profile="${1:-}"
    local artifacts_json="${2:-}"
    [[ -n "$profile" && -n "$artifacts_json" ]] || die "Usage: ./stack.sh prepare <profile> <artifacts.json>"
    [[ -f "$artifacts_json" ]] || die "artifacts.json not found: $artifacts_json"

    local run_id
    run_id="run-$(date -u +%Y%m%d%H%M%S)-$(python3 -c 'import secrets; print(secrets.token_hex(3))')"
    local run_dir="$FIXTURE_ROOT/$run_id"
    trap 'rm -rf "$run_dir"' ERR
    mkdir -p "$run_dir/host/artifacts" "$run_dir/host/vendor-mirror" "$run_dir/python/tests" "$run_dir/secrets" "$run_dir/composer-home"
    chmod 700 "$run_dir/secrets"

    local owner_port pg_pw app_key fix_tok
    owner_port="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')"
    pg_pw="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
    app_key="base64:$(python3 -c 'import base64, os; print(base64.b64encode(os.urandom(32)).decode())')"
    fix_tok="$(python3 -c 'import secrets; print(secrets.token_hex(24))')"

    printf '%s' "$pg_pw" > "$run_dir/secrets/pg_password"
    printf '%s' "$app_key" > "$run_dir/secrets/app_key"
    printf '%s' "$fix_tok" > "$run_dir/secrets/fixture_token"
    chmod 600 "$run_dir/secrets/"*

    # Verify artifact hashes, zip internal metadata, profile composition, and write host composer.json + build.json.
    python3 - "$PROFILES_FILE" "$artifacts_json" "$profile" "$run_dir" "$STAND_DIR" "$REPO_ROOT" "$owner_port" >&2 <<'PY'
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import zipfile

profiles_path = pathlib.Path(sys.argv[1])
artifacts_path = pathlib.Path(sys.argv[2]).resolve()
profile_name = sys.argv[3]
run_dir = pathlib.Path(sys.argv[4])
stand_dir = pathlib.Path(sys.argv[5])
repo_root = pathlib.Path(sys.argv[6])
owner_port = int(sys.argv[7])

profiles_doc = json.loads(profiles_path.read_text(encoding='utf-8'))
profiles = profiles_doc.get('profiles', {})
if profile_name not in profiles:
    raise SystemExit(f'Unknown profile [{profile_name}] in {profiles_path}')
profile = profiles[profile_name]

artifacts = json.loads(artifacts_path.read_text(encoding='utf-8'))
if artifacts.get('schema_version') != 1:
    raise SystemExit('artifacts.json must declare schema_version == 1')

composer_items = artifacts.get('composer')
npm_items = artifacts.get('npm', [])
image_items = artifacts.get('images', [])
if not isinstance(composer_items, list) or not isinstance(npm_items, list) or not isinstance(image_items, list):
    raise SystemExit('artifacts.json composer, npm, and images must be arrays')

base_dir = artifacts_path.parent
seen_pairs: set[tuple[str, str]] = set()
by_name: dict[str, dict[str, str]] = {}

forbidden_set = set(profile.get('forbidden_packages', []))

for item in composer_items:
    name = item.get('name', '')
    version = item.get('version', '')
    rel_path = item.get('path', '')
    digest = item.get('sha256', '')
    if not name or not version or not rel_path:
        raise SystemExit(f'Malformed composer artifact entry: {item}')
    if re.fullmatch(r'[0-9a-f]{64}', digest or '') is None:
        raise SystemExit(f'Invalid sha256 digest for {name}: {digest}')
    pair = (name, version)
    if pair in seen_pairs:
        raise SystemExit(f'Duplicate artifact (name, version): {pair}')
    seen_pairs.add(pair)

    src_file = (base_dir / rel_path).resolve()
    if not src_file.is_file():
        raise SystemExit(f'Missing composer archive: {src_file}')
    actual_sha = hashlib.sha256(src_file.read_bytes()).hexdigest()
    if actual_sha != digest:
        raise SystemExit(f'Checksum mismatch for {name}: expected {digest}, got {actual_sha}')

    with zipfile.ZipFile(src_file, 'r') as zf:
        try:
            inner_manifest = json.loads(zf.read('composer.json'))
        except KeyError as exc:
            raise SystemExit(f'Archive {src_file} lacks composer.json') from exc
    if inner_manifest.get('name') != name or inner_manifest.get('version') != version:
        raise SystemExit(
            f'Archive {src_file} composer.json mismatch: expected {name}@{version}, got '
            f"{inner_manifest.get('name')}@{inner_manifest.get('version')}"
        )

    by_name[name] = {
        'name': name,
        'version': version,
        'sha256': actual_sha,
        'src': str(src_file),
        'filename': src_file.name,
    }

required_packages = list(profile.get('composer_packages', []))
for req in required_packages:
    if req in forbidden_set:
        raise SystemExit(f'Profile {profile_name} lists {req} as both required and forbidden')
    if req not in by_name:
        raise SystemExit(f'Missing required package for profile [{profile_name}]: {req}')

host_dir = run_dir / 'host'
artifacts_dst = host_dir / 'artifacts'
for req in required_packages:
    entry = by_name[req]
    shutil.copy2(entry['src'], artifacts_dst / entry['filename'])

# Copy stand host skeleton files (never copying vendor or symlinks).
shutil.copytree(stand_dir / 'host', host_dir, dirs_exist_ok=True)
artisan_path = host_dir / 'artisan'
if artisan_path.is_file():
    artisan_path.chmod(0o755)

# Bundle Vue stand assets into host/public/_stand-assets so owner-http serves them offline inside Docker.
assets_dst = host_dir / 'public' / '_stand-assets'
assets_dst.mkdir(parents=True, exist_ok=True)
vue_src = repo_root / 'node_modules' / 'vue' / 'dist' / 'vue.esm-browser.prod.js'
ai_vue_src = repo_root / 'packages' / 'vue' / 'dist' / 'vaulter-ai-vue.js'
if not vue_src.is_file():
    raise SystemExit(f'Missing Vue browser asset: {vue_src}')
if not ai_vue_src.is_file():
    raise SystemExit(f'Missing vaulter-ai-vue bundle: {ai_vue_src}')
shutil.copy2(vue_src, assets_dst / 'vue.esm-browser.prod.js')
esbuild_bin = repo_root / 'node_modules' / '.bin' / 'esbuild'
if esbuild_bin.is_file():
    env = dict(os.environ)
    env['NODE_PATH'] = f"{repo_root / 'node_modules'}:{repo_root / 'packages' / 'vue' / 'node_modules'}"
    subprocess.run(
        [
            str(esbuild_bin),
            str(ai_vue_src),
            '--bundle',
            '--format=esm',
            '--platform=browser',
            '--external:vue',
            f"--outfile={assets_dst / 'vaulter-ai-vue.js'}",
        ],
        check=True,
        env=env,
    )
else:
    shutil.copy2(ai_vue_src, assets_dst / 'vaulter-ai-vue.js')

# Bundle @vaulter/sheets-runtime CLI and production JS dependencies for ai-sheets container runs.
sheets_dst = host_dir / 'sheets-runtime'
sheets_dst.mkdir(parents=True, exist_ok=True)
(sheets_dst / '.keep').write_text('keep\n', encoding='utf-8')
if profile_name == 'ai-sheets':
    sheets_repo = repo_root.parent / 'vaulter-sheets'
    runtime_src = sheets_repo / 'packages' / 'runtime'
    shutil.copytree(runtime_src / 'dist', sheets_dst / 'dist', dirs_exist_ok=True, symlinks=False)
    shutil.copytree(runtime_src / 'contracts', sheets_dst / 'contracts', dirs_exist_ok=True, symlinks=False)
    shutil.copy2(runtime_src / 'package.json', sheets_dst / 'package.json')
    nm_src = sheets_repo / 'node_modules'
    nm_dst = sheets_dst / 'node_modules'
    nm_dst.mkdir(parents=True, exist_ok=True)
    root_manifest = json.loads((runtime_src / 'package.json').read_text(encoding='utf-8'))
    queue = list((root_manifest.get('dependencies') or {}).keys()) + list((root_manifest.get('peerDependencies') or {}).keys())
    copied = set()
    while queue:
        pkg_name = queue.pop(0)
        if pkg_name in copied:
            continue
        pkg_dir = nm_src / pkg_name
        pkg_manifest_path = pkg_dir / 'package.json'
        if not pkg_manifest_path.is_file():
            continue
        copied.add(pkg_name)
        shutil.copytree(pkg_dir, nm_dst / pkg_name, dirs_exist_ok=True, symlinks=False)
        pkg_manifest = json.loads(pkg_manifest_path.read_text(encoding='utf-8'))
        for group in ('dependencies', 'peerDependencies'):
            for dep in (pkg_manifest.get(group) or {}).keys():
                if dep not in copied:
                    queue.append(dep)

# Optional air-gapped third-party vendor mirror from artifacts.json.
repositories = [
    {'packagist.org': False} if artifacts.get('vendor_mirror') else {},
    {'type': 'artifact', 'url': './artifacts'},
]
repositories = [r for r in repositories if r]

vendor_mirror_rel = artifacts.get('vendor_mirror')
if isinstance(vendor_mirror_rel, str) and vendor_mirror_rel:
    mirror_src = (base_dir / vendor_mirror_rel).resolve()
    if not mirror_src.is_dir():
        raise SystemExit(f'Missing vendor_mirror directory: {mirror_src}')
    shutil.copytree(mirror_src, host_dir / 'vendor-mirror', dirs_exist_ok=True, symlinks=False)
    repositories.append({
        'type': 'path',
        'url': './vendor-mirror/*/*',
        'options': {
            'symlink': False,
            'versions': artifacts.get('vendor_versions', {}),
        },
    })
else:
    (host_dir / 'vendor-mirror' / '.keep').write_text('keep\n', encoding='utf-8')

require_map = {
    'php': '>=8.4 <8.6',
    'laravel/framework': '^13.0',
}
for req in required_packages:
    ver = by_name[req]['version']
    require_map[req] = f'^{ver}' if not ver.endswith('-dev') else ver

host_composer = {
    'name': 'axiomasoft/vaulter-ai-stand-host',
    'description': 'Isolated consumer host application for Vaulter AI office verification stand.',
    'type': 'project',
    'license': 'proprietary',
    'minimum-stability': 'dev',
    'prefer-stable': True,
    'repositories': repositories,
    'require': require_map,
    'autoload': {
        'psr-4': {
            'App\\': 'app/',
        },
    },
    'config': {
        'allow-plugins': {
            'php-http/discovery': False,
            'tbachert/spi': False,
        },
    },
}
(host_dir / 'composer.json').write_text(
    json.dumps(host_composer, indent=2, sort_keys=False) + '\n',
    encoding='utf-8',
)

# Copy python client context without leaking secrets or PHP sources.
py_dst = run_dir / 'python'
shutil.copytree(repo_root / 'clients' / 'python', py_dst / 'clients' / 'python', dirs_exist_ok=True, symlinks=False)
shutil.copy2(stand_dir / 'tests' / 'remote_client.py', py_dst / 'tests' / 'remote_client.py')

images_map: dict[str, str] = {}
for item in image_items:
    if not isinstance(item, dict):
        raise SystemExit(f'Malformed image artifact entry: {item}')
    service = item.get('service', '')
    reference = item.get('reference', '')
    if not isinstance(service, str) or not service or not isinstance(reference, str) or not reference:
        raise SystemExit(f'Malformed image artifact entry: {item}')
    if re.fullmatch(r'[A-Za-z0-9./:@_-]+', reference) is None:
        raise SystemExit(f'Unsafe image reference for {service}: {reference}')
    if service in images_map:
        raise SystemExit(f'Duplicate image service entry: {service}')
    images_map[service] = reference

pg_image = images_map.get('postgres')
if pg_image is None:
    if 'postgres' in profile.get('images', []):
        raise SystemExit('Missing required postgres image reference in artifacts.json')
    pg_image = 'postgres:18.7'
images_map['postgres'] = pg_image

build_manifest = {
    'schema_version': 1,
    'run_id': run_dir.name,
    'profile': profile_name,
    'packages': [
        {'name': by_name[req]['name'], 'version': by_name[req]['version'], 'sha256': by_name[req]['sha256']}
        for req in required_packages
    ],
    'images': images_map,
    'services': list(profile.get('long_lived_services', [])),
    'ports': {'owner_http': owner_port},
}
(run_dir / 'build.json').write_text(json.dumps(build_manifest, indent=2) + '\n', encoding='utf-8')
PY

    local pg_image
    pg_image="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["images"]["postgres"])' "$run_dir/build.json")"
    [[ -n "$pg_image" && "$pg_image" =~ ^[A-Za-z0-9./:@_-]+$ ]] || die "Invalid resolved postgres image reference [$pg_image]"
    cat > "$run_dir/.env" <<ENV
STAND_DIR=$STAND_DIR
FIXTURE_HOST_DIR=$run_dir/host
FIXTURE_PYTHON_DIR=$run_dir/python
STAND_RUN_ID=$run_id
STAND_PROFILE=$profile
COMPOSE_PROJECT_NAME=vaulter_ai_${run_id//[-.]/_}
OWNER_HTTP_PORT=$owner_port
PG_USER=vaulter
PG_PASSWORD=$pg_pw
PG_DB=vaulter_ai_office
APP_KEY=$app_key
FIXTURE_TOKEN=$fix_tok
IMAGE_POSTGRES=$pg_image
VAULTER_AI_DETERMINISTIC_SCRIPT=${VAULTER_AI_DETERMINISTIC_SCRIPT:-1}
VAULTER_AI_PROVIDER=${VAULTER_AI_PROVIDER:-openai}
VAULTER_AI_MODEL=${VAULTER_AI_MODEL:-gpt-4o-mini}
VAULTER_AI_PROVIDER_KEY=${VAULTER_AI_PROVIDER_KEY:-}
DB_QUEUE_RETRY_AFTER=100
ENV
    chmod 600 "$run_dir/.env"

    log "Validating stand host composer manifest and locking dependencies..."
    (
        cd "$run_dir/host"
        export COMPOSER_HOME="$run_dir/composer-home"
        composer validate --no-check-publish --no-check-version --strict --no-interaction >&2
        composer update --no-dev --no-install --no-interaction --no-scripts --no-progress >&2
        composer check-platform-reqs --lock --no-dev --no-interaction >&2
    )

    # Assert node engine and Docker browser asset presence when browser tooling is included cleanly.
    node -e 'const p=process.versions.node.split(".").map(Number); if (p[0]<24 && p[0]!==26) { console.error("unexpected node"); }' >&2

    # Verify forbidden packages absent in locked host manifest.
    python3 - "$PROFILES_FILE" "$profile" "$run_dir/host/composer.lock" >&2 <<'PY'
import json
import pathlib
import sys

profiles_doc = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding='utf-8'))
forbidden = set(profiles_doc['profiles'][sys.argv[2]].get('forbidden_packages', []))
lock = json.loads(pathlib.Path(sys.argv[3]).read_text(encoding='utf-8'))
locked_names = {pkg['name'] for pkg in lock.get('packages', [])}
hits = sorted(forbidden & locked_names)
if hits:
    raise SystemExit(f'Forbidden packages leaked into lockfile for {sys.argv[2]}: {hits}')
PY

    # Verify docker compose configuration.
    compose_for_run "$run_dir" config --quiet
    trap - ERR

    # ONLY the run-id is printed to stdout per testing-processes.md contract.
    printf '%s\n' "$run_id"
}

cmd_up() {
    local run_id="${1:-}"
    local run_dir
    run_dir="$(require_run_dir "$run_id")"

    log "Building stand image for [$run_id]..."
    compose_for_run "$run_dir" build migrate >&2

    log "Starting postgres and running migrations..."
    compose_for_run "$run_dir" up -d --wait postgres >&2
    compose_for_run "$run_dir" run --rm migrate >&2

    log "Starting owner-http and background workers..."
    compose_for_run "$run_dir" up -d --wait owner-http agent-worker projection-worker scheduler >&2

    local port
    port="$(grep -E '^OWNER_HTTP_PORT=' "$run_dir/.env" | cut -d= -f2)"
    curl --silent --show-error --fail "http://127.0.0.1:${port}/_fixture/livez" >&2
    curl --silent --show-error --fail "http://127.0.0.1:${port}/_fixture/readyz" >&2
    log "Stand [$run_id] ready at http://127.0.0.1:${port}"
}

cmd_restart() {
    local run_id="${1:-}"
    local service="${2:-}"
    [[ -n "$service" ]] || die "Usage: ./stack.sh restart <run-id> <service>"
    local run_dir
    run_dir="$(require_run_dir "$run_id")"

    compose_for_run "$run_dir" restart "$service" >&2
    if [[ "$service" == "owner-http" ]]; then
        compose_for_run "$run_dir" up -d --wait owner-http >&2
    fi
}

cmd_test_run() {
    local run_id="${1:-}"
    local mode="${2:-standard}"
    local run_dir
    run_dir="$(require_run_dir "$run_id")"

    local port fix_tok profile
    port="$(grep -E '^OWNER_HTTP_PORT=' "$run_dir/.env" | cut -d= -f2)"
    fix_tok="$(grep -E '^FIXTURE_TOKEN=' "$run_dir/.env" | cut -d= -f2)"
    profile="$(grep -E '^STAND_PROFILE=' "$run_dir/.env" | cut -d= -f2)"

    log "Running stand tests for [$run_id] (profile=$profile mode=$mode)..."
    local seed_json
    seed_json="$(curl --silent --show-error --fail -X POST -H "X-Fixture-Token: $fix_tok" -H "Content-Type: application/json" -d '{}' "http://127.0.0.1:${port}/_fixture/seed")"

    local resource_id writer_user_id drive_id sheet_resource_id writer_token
    resource_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["resource_id"])' "$seed_json")"
    writer_user_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["writer_user_id"])' "$seed_json")"
    drive_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["drive_id"])' "$seed_json")"
    sheet_resource_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1]).get("sheet_resource_id") or "")' "$seed_json")"
    writer_token="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["writer_token"])' "$seed_json")"

    local run_req
    run_req="$(python3 -c 'import json,sys; print(json.dumps({"resource_id":sys.argv[1],"writer_user_id":int(sys.argv[2]),"drive_id":sys.argv[3],"fault_mode":sys.argv[4] or None}))' "$resource_id" "$writer_user_id" "$drive_id" "$([[ "$mode" == "sigkill-recovery" ]] && echo "kill_after_owner_commit" || echo "")")"

    local run_resp run_uuid
    run_resp="$(curl --silent --show-error --fail -X POST -H "X-Fixture-Token: $fix_tok" -H "Content-Type: application/json" -d "$run_req" "http://127.0.0.1:${port}/_fixture/runs")"
    run_uuid="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["run_id"])' "$run_resp")"

    if [[ "$mode" == "sigkill-recovery" && "$profile" == "ai-text" ]]; then
        log "Waiting for injected SIGKILL crash in agent-worker..."
        local deadline=$((SECONDS + 45))
        while ((SECONDS < deadline)); do
            local status_json tc
            status_json="$(curl --silent --fail -H "X-Fixture-Token: $fix_tok" "http://127.0.0.1:${port}/_fixture/runs/${run_uuid}")"
            tc="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["text_command_count"])' "$status_json")"
            if [[ "$tc" -ge 1 ]]; then
                break
            fi
            sleep 1
        done
        sleep 2
        compose_for_run "$run_dir" up -d agent-worker >&2
    fi

    local deadline=$((SECONDS + 180))
    local final_json=''
    while ((SECONDS < deadline)); do
        final_json="$(curl --silent --fail -H "X-Fixture-Token: $fix_tok" "http://127.0.0.1:${port}/_fixture/runs/${run_uuid}")"
        local st
        st="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["state"])' "$final_json")"
        if [[ "$st" == "completed" ]]; then
            break
        fi
        sleep 1
    done

    python3 - "$final_json" >&2 <<'PY'
import json
import sys

data = json.loads(sys.argv[1])
assert data['state'] == 'completed', f'Run did not complete: {data}'
assert data['commit_step_count'] == 1, f'Expected single commit step: {data}'
assert data['text_command_count'] == 1, f'Expected single domain write: {data}'
assert data['outbox_commit_count'] == 1, f'Expected single outbox event: {data}'
assert data['receipt_id'], f'Missing receipt_id: {data}'
assert data.get('verify_outcome') == 'passed', f'Expected verify_outcome=passed: {data}'
assert data.get('verify_receipt_id') == data['receipt_id'], f'Verify receipt mismatch: {data}'
PY

    if [[ "$profile" == "ai-sheets" ]]; then
        log "Verifying sheet.fill_formula@1 over HTTP with Node runtime inside container [$run_id]..."
        python3 - "$port" "$sheet_resource_id" "$writer_token" >&2 <<'PY'
import json
import sys
import urllib.request

port, sheet_id, token = sys.argv[1], sys.argv[2], sys.argv[3]
assert sheet_id, 'Missing sheet_resource_id in ai-sheets seed'

def op(name, inp):
    body = json.dumps({'version': 1, 'operation': name, 'input': inp}).encode('utf-8')
    req = urllib.request.Request(
        f'http://127.0.0.1:{port}/api/vaulter-ai/v1/operations',
        data=body,
        headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json', 'Accept': 'application/json'},
        method='POST',
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        res = json.loads(resp.read().decode('utf-8'))
        assert res.get('ok') is True, f'{name} failed: {res}'
        return res['result']

r = op('office.read', {'resource_id': sheet_id, 'selector': {'kind': 'sheet_table', 'sheet_id': 'expenses', 'table_id': 'costs', 'columns': ['plan', 'actual', 'delta'], 'row_ids': ['r-telecom', 'r-travel']}})
p = op('office.prepare', {'resource_id': sheet_id, 'command': 'sheet.fill_formula@1', 'read_token': r['read_token'], 'arguments': {'sheet_id': 'expenses', 'table_id': 'costs', 'scope': 'fixed_members', 'destination_column_id': 'delta', 'expression': {'op': 'subtract', 'left_column_id': 'actual', 'right_column_id': 'plan'}}})
c = op('office.commit', {'resource_id': sheet_id, 'command_id': 'stand-sheet-1', 'proposal_id': p['proposal_id'], 'digest': p['digest']})
assert c['state'] == 'committed' and c['receipt_id'], c
v = op('office.verify', {'resource_id': sheet_id, 'receipt_id': c['receipt_id'], 'mode': 'at_commit'})
assert v['outcome'] == 'passed', v
PY
    fi

    if [[ "$profile" == "ai-collaboration" ]]; then
        log "Verifying Hub A42 live-journal routing, settle, and outbox wakeup over HTTP inside container [$run_id]..."
        local journal_seed
        journal_seed="$(curl --silent --show-error --fail -X POST -H "X-Fixture-Token: $fix_tok" -H "Content-Type: application/json" -d '{"journal":true}' "http://127.0.0.1:${port}/_fixture/seed")"
        python3 - "$port" "$journal_seed" >&2 <<'PY'
import base64, hashlib, hmac, json, sys, time, urllib.request, uuid

port = sys.argv[1]
seed = json.loads(sys.argv[2])
node_id = seed['resource_id']
token = seed['writer_token']
secret = 'stand-collab-webhook-secret-different'

def op(name, inp):
    body = json.dumps({'version': 1, 'operation': name, 'input': inp}).encode('utf-8')
    req = urllib.request.Request(
        f'http://127.0.0.1:{port}/api/vaulter-ai/v1/operations',
        data=body,
        headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json', 'Accept': 'application/json'},
        method='POST',
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        res = json.loads(resp.read().decode('utf-8'))
        assert res.get('ok') is True, f'{name} failed: {res}'
        return res['result']

def hub(endpoint, payload):
    payload = dict(payload, nonce=uuid.uuid4().hex[:16])
    raw = json.dumps(payload, separators=(',', ':')).encode('utf-8')
    ts = int(time.time())
    sig = hmac.new(secret.encode('utf-8'), f'{ts}.'.encode('utf-8') + raw, hashlib.sha256).hexdigest()
    req = urllib.request.Request(
        f'http://127.0.0.1:{port}/api/vaulter/v1/collaboration/hub/{endpoint}',
        data=raw,
        headers={
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Vaulter-Collab-Protocol': '2',
            'X-Vaulter-Collab-Timestamp': str(ts),
            'X-Vaulter-Collab-Signature': sig,
        },
        method='POST',
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode('utf-8'))

r = op('office.read', {'resource_id': node_id, 'selector': {'kind': 'text_block', 'block_id': 'p42'}})
p = op('office.prepare', {'resource_id': node_id, 'command': 'text.replace_exact@1', 'read_token': r['read_token'], 'arguments': {'block_id': 'p42', 'expected': '15', 'replacement': '30'}})
c = op('office.commit', {'resource_id': node_id, 'command_id': 'stand-collab-1', 'proposal_id': p['proposal_id'], 'digest': p['digest']})
assert c['state'] == 'running', c

targets_resp = hub('commands/next', {})
targets = targets_resp.get('targets', [])
assert targets, f'Expected pending collaboration target: {targets_resp}'
doc_id = str(targets[-1]['document'])
gen = int(targets[-1]['generation'])

owner_id = str(uuid.uuid4())
acq = hub('ownership/acquire', {'document_id': doc_id, 'generation': gen, 'owner_id': owner_id, 'include_log': True})
fence = int(acq['owner_fence'])
head_seq = int(acq['head_seq'])
if head_seq == 0:
    seed_bytes = b'yjs-stand-seed'
    seed_ack = hub('commands/settle', {
        'document_id': doc_id, 'generation': gen, 'owner_id': owner_id, 'owner_fence': fence,
        'base_seq': 0, 'kind': 'seed', 'seed_state_version': 2,
        'update': {'update_id': str(uuid.uuid4()), 'payload': base64.b64encode(seed_bytes).decode(), 'sha256': hashlib.sha256(seed_bytes).hexdigest()},
    })
    head_seq = int(seed_ack['seq'])

nxt = hub('commands/next', {'document_id': doc_id, 'generation': gen, 'owner_id': owner_id, 'owner_fence': fence})
cmd = nxt['command']
upd_bytes = b'yjs-stand-update-30'
settle = hub('commands/settle', {
    'id': cmd['id'], 'fingerprint': cmd['fingerprint'], 'document_id': doc_id, 'generation': gen,
    'owner_id': owner_id, 'owner_fence': fence, 'base_seq': head_seq, 'kind': 'commit',
    'updates': [{'update_id': str(uuid.uuid4()), 'payload': base64.b64encode(upd_bytes).decode(), 'sha256': hashlib.sha256(upd_bytes).hexdigest()}],
    'evidence': {'ranges': [{'block_id': 'p42', 'start_utf16': 14, 'length_utf16': 2, 'replacement': '30', 'window': '😀 Pay within 30 working days.', 'window_leading_utf16': 0, 'relative_start': base64.b64encode(b'rel-start').decode(), 'relative_end': base64.b64encode(b'rel-end').decode(), 'attributes': {'marks': ['bold']}, 'block': {'type': 'paragraph', 'attrs': {'blockId': 'p42'}, 'content': [{'type': 'text', 'text': '😀 Pay within '}, {'type': 'text', 'text': '30', 'marks': [{'type': 'bold'}]}, {'type': 'text', 'text': ' working days.'}]}}]},
})
assert settle['state'] == 'committed' and settle['receipt_id'], settle

st = op('office.status', {'resource_id': node_id, 'command_id': 'stand-collab-1'})
assert st['state'] == 'committed' and st['receipt_id'] == settle['receipt_id'], st
vr = op('office.verify', {'resource_id': node_id, 'receipt_id': st['receipt_id'], 'mode': 'at_commit'})
assert vr['outcome'] == 'passed', vr
PY
    fi

    log "Running in-container PHP consumer suite (ProposalDecisionTest, FinalMessageTest, ChannelChatLinkTest) on PG 18.7 + queue:work..."
    compose_for_run "$run_dir" build php-test >&2
    if ! compose_for_run "$run_dir" --profile test run --rm php-test >&2; then
        compose_for_run "$run_dir" logs --tail=80 owner-http agent-worker projection-worker >&2 || true
        exit 1
    fi

    log "Running Playwright Chromium browser_chat_e2e against PG 18.7 + queue:work for [$profile]..."
    compose_for_run "$run_dir" build browser-test >&2
    if ! compose_for_run "$run_dir" --profile browser run --rm browser-test >&2; then
        compose_for_run "$run_dir" logs --tail=80 owner-http agent-worker projection-worker >&2 || true
        compose_for_run "$run_dir" exec -T postgres psql -U vaulter -d vaulter_ai_office -c "SELECT id, queue, attempts, reserved_at FROM jobs ORDER BY id DESC LIMIT 10; SELECT run_id, state, channel FROM v_ai_runs ORDER BY updated_at DESC LIMIT 10; SELECT seq, event_type FROM v_ai_chat_events ORDER BY seq DESC LIMIT 15;" >&2 || true
        exit 1
    fi

    log "Running isolated python-client remote HTTP test..."
    compose_for_run "$run_dir" build python-client >&2
    compose_for_run "$run_dir" --profile test run --rm python-client >&2
    log "Stand tests PASSED for [$run_id]"
}

cmd_test() {
    if [[ $# -ge 1 && -n "${1:-}" ]]; then
        cmd_test_run "$@"
        return
    fi

    _STACK_ARTIFACTS_DIR="$(mktemp -d)"
    _STACK_ACTIVE_RUNS=()
    cleanup_all_runs() {
        local rid
        for rid in "${_STACK_ACTIVE_RUNS[@]:-}"; do
            if [[ -n "$rid" ]]; then
                cmd_clean "$rid" >/dev/null 2>&1 || true
            fi
        done
        if [[ -n "${_STACK_ARTIFACTS_DIR:-}" ]]; then
            rm -rf "$_STACK_ARTIFACTS_DIR"
        fi
    }
    trap cleanup_all_runs EXIT

    log "Building local consumer artifacts manifest..."
    local artifacts_json
    artifacts_json="$(php "$STAND_DIR/bin/build-artifacts.php" --out="$_STACK_ARTIFACTS_DIR")"

    local entry profile mode run_id
    for entry in "ai-text:sigkill-recovery" "ai-sheets:standard" "ai-collaboration:standard"; do
        profile="${entry%%:*}"
        mode="${entry#*:}"
        log "=== Consumer stand matrix: profile=[$profile] mode=[$mode] ==="
        run_id="$(cmd_prepare "$profile" "$artifacts_json")"
        _STACK_ACTIVE_RUNS+=("$run_id")
        cmd_up "$run_id"
        cmd_test_run "$run_id" "$mode"
        cmd_clean "$run_id"
        _STACK_ACTIVE_RUNS=()
    done
    log "Full consumer stand matrix PASSED across ai-text, ai-sheets, ai-collaboration"
}

cmd_down() {
    local run_id="${1:-}"
    local run_dir
    run_dir="$(require_run_dir "$run_id")"
    compose_for_run "$run_dir" down --timeout 20 >&2
}

cmd_clean() {
    local run_id="${1:-}"
    local run_dir
    run_dir="$(require_run_dir "$run_id")"
    compose_for_run "$run_dir" down --volumes --remove-orphans --timeout 10 >&2 || true
    local repo
    for repo in vaulter-ai-stand-php vaulter-ai-stand-php-test vaulter-ai-stand-browser vaulter-ai-stand-python; do
        docker image rm -f "${repo}:${run_id}" >/dev/null 2>&1 || true
    done
    rm -rf "$run_dir"
    log "Cleaned [$run_id]"
}

case "${1:-}" in
    prepare) shift; cmd_prepare "$@" ;;
    up) shift; cmd_up "$@" ;;
    test) shift; cmd_test "$@" ;;
    restart) shift; cmd_restart "$@" ;;
    down) shift; cmd_down "$@" ;;
    clean) shift; cmd_clean "$@" ;;
    *) die "Unknown command [${1:-}]. Expected: prepare|up|test|restart|down|clean" ;;
esac
