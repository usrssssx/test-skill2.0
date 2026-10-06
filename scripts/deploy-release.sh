#!/usr/bin/env bash
set -euo pipefail

deploy_path="${1:-}"
revision="${2:-}"
archive="${3:-}"
health_url="${4:-}"
php_binary="${5:-/usr/bin/php8.5}"
[[ "$php_binary" =~ ^/[A-Za-z0-9._/-]+$ && -x "$php_binary" ]] || { echo "ERROR: invalid PHP runtime" >&2; exit 2; }

[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "ERROR: invalid deploy path" >&2; exit 2; }
[[ "$revision" =~ ^[A-Za-z0-9._-]{7,64}$ ]] || { echo "ERROR: invalid revision" >&2; exit 2; }
[[ "$archive" =~ ^/tmp/release-[A-Za-z0-9._-]+\.tar\.gz$ ]] || { echo "ERROR: invalid archive path" >&2; exit 2; }
[[ "$health_url" =~ ^https://[A-Za-z0-9._:/-]+$ ]] || { echo "ERROR: invalid health URL" >&2; exit 2; }

mkdir -p "$deploy_path/releases" "$deploy_path/shared/storage" "$deploy_path/shared/backups"
exec 9>"$deploy_path/deploy.lock"
flock -n 9 || { echo "ERROR: another deployment is running" >&2; exit 1; }

release="$deploy_path/releases/$revision"
runtime_reload="$deploy_path/shared/reload-runtime"
previous=""
if [[ -L "$deploy_path/current" ]]; then
  previous="$(readlink -f "$deploy_path/current")"
fi

reload_runtime() {
  if [[ -x "$runtime_reload" ]]; then
    "$runtime_reload"
  fi
}

wait_for_revision() {
  local expected="$1"
  local observed=""
  local consecutive=0

  for _ in {1..60}; do
    observed="$(curl --fail --show-error --silent --max-time 10 "$health_url/health" 2>/dev/null | "$php_binary" -r '$payload=json_decode(stream_get_contents(STDIN), true); echo is_array($payload) ? ($payload["revision"] ?? "") : "";' 2>/dev/null || true)"
    if [[ "$observed" == "$expected" ]]; then
      consecutive=$((consecutive + 1))
      if (( consecutive >= 3 )); then
        return 0
      fi
    else
      consecutive=0
    fi
    sleep 2
  done

  echo "ERROR: HTTP health did not stabilize on revision $expected; last observed: ${observed:-<empty>}" >&2
  return 1
}

if [[ -e "$release" ]]; then
  if [[ "$previous" == "$release" ]]; then
    reload_runtime
    wait_for_revision "$revision"
    rm -f "$archive" "$0"
    echo "DEPLOYED_REVISION=$revision"
    exit 0
  fi
  rm -rf -- "$release"
fi

mkdir "$release"
tar -xzf "$archive" -C "$release"
printf '%s\n' "$revision" > "$release/REVISION"

if [[ ! -f "$deploy_path/shared/.env" ]]; then
  echo "ERROR: server environment file is missing: $deploy_path/shared/.env" >&2
  exit 1
fi

ln -s "$deploy_path/shared/.env" "$release/.env"
rm -rf "$release/storage"
ln -s "$deploy_path/shared/storage" "$release/storage"

cd "$release"
"$php_binary" artisan config:cache
"$php_binary" artisan route:cache
"$php_binary" artisan view:cache

ln -s "$release" "$deploy_path/current.next"
mv -Tf "$deploy_path/current.next" "$deploy_path/current"

rollback_application() {
  local exit_code=$?
  trap - ERR
  if [[ -n "$previous" && -d "$previous" ]]; then
    local previous_revision="${previous##*/}"
    set +e
    ln -s "$previous" "$deploy_path/current.rollback"
    mv -Tf "$deploy_path/current.rollback" "$deploy_path/current"
    reload_runtime
    cd "$previous"
    "$php_binary" artisan queue:restart || true
    wait_for_revision "$previous_revision" || true
  fi
  exit "$exit_code"
}
trap rollback_application ERR

reload_runtime
"$php_binary" artisan queue:restart
wait_for_revision "$revision"

trap - ERR
rm -f "$archive" "$0"
echo "DEPLOYED_REVISION=$revision"
