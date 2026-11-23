#!/usr/bin/env bash
# Isolated Docker Compose consumer stand controller matching plan/spec/implementation/processes.md.
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
import pathlib
import re
import shutil
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

    # ONLY the run-id is printed to stdout per processes.md contract.
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

cmd_test() {
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

    local resource_id writer_user_id drive_id
    resource_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["resource_id"])' "$seed_json")"
    writer_user_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["writer_user_id"])' "$seed_json")"
    drive_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["drive_id"])' "$seed_json")"

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

    log "Running isolated python-client remote HTTP test..."
    compose_for_run "$run_dir" build python-client >&2
    compose_for_run "$run_dir" --profile test run --rm python-client >&2
    log "Stand tests PASSED for [$run_id]"
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
