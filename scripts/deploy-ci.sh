#!/usr/bin/env bash
set -euo pipefail

environment="${1:-}"
revision="${2:-}"
archive="${3:-}"
PHP_BINARY="${PHP_BINARY:-/usr/bin/php8.5}"
[[ "$PHP_BINARY" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "ERROR: invalid PHP binary" >&2; exit 2; }

for variable in HOST PORT USER PATH_ON_SERVER APP_URL; do
  if [[ -z "${!variable:-}" ]]; then
    echo "ERROR: $variable is required" >&2
    exit 2
  fi
done

[[ "$environment" =~ ^(test|production)$ ]] || { echo "ERROR: invalid environment" >&2; exit 2; }
[[ "$revision" =~ ^[A-Za-z0-9._-]{7,64}$ ]] || { echo "ERROR: invalid revision" >&2; exit 2; }
[[ "$HOST" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "ERROR: invalid host" >&2; exit 2; }
[[ "$PORT" =~ ^[0-9]{1,5}$ ]] || { echo "ERROR: invalid port" >&2; exit 2; }
[[ "$USER" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || { echo "ERROR: invalid user" >&2; exit 2; }
[[ "$PATH_ON_SERVER" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "ERROR: invalid server path" >&2; exit 2; }
[[ "$APP_URL" =~ ^https://[A-Za-z0-9._:/-]+$ ]] || { echo "ERROR: invalid app URL" >&2; exit 2; }
[[ -f "$archive" ]] || { echo "ERROR: release archive is missing" >&2; exit 2; }
[[ -f "$archive.sha256" ]] || { echo "ERROR: release checksum is missing" >&2; exit 2; }
sha256sum --check "$archive.sha256"

ssh_options=(-i "$HOME/.ssh/deploy_key" -p "$PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes)
scp_options=(-i "$HOME/.ssh/deploy_key" -P "$PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes)
remote_archive="/tmp/release-${revision}.tar.gz"
remote_script="/tmp/deploy-release-${revision}.sh"

scp "${scp_options[@]}" "$archive" "$USER@$HOST:$remote_archive"
scp "${scp_options[@]}" scripts/deploy-release.sh "$USER@$HOST:$remote_script"

ssh "${ssh_options[@]}" "$USER@$HOST" \
  "bash '$remote_script' '$PATH_ON_SERVER' '$revision' '$remote_archive' '$APP_URL' '$PHP_BINARY'"

current_release="$(ssh "${ssh_options[@]}" "$USER@$HOST" "readlink -f '$PATH_ON_SERVER/current'")"
[[ "${current_release##*/}" == "$revision" ]] || {
  echo "ERROR: current release does not match revision: $current_release" >&2
  exit 1
}

health_revision="$(curl --fail --show-error --silent --retry 5 --retry-delay 2 "$APP_URL/health" | php -r '$payload=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $payload["revision"] ?? "";')"
[[ "$health_revision" == "$revision" ]] || {
  echo "ERROR: HTTP health revision does not match: $health_revision" >&2
  exit 1
}
