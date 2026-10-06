#!/usr/bin/env bash
set -euo pipefail

root="${1:-}"
if [[ -z "$root" || ! -d "$root" ]]; then
  echo "Usage: check-deploy-artifact.sh <artifact-directory>" >&2
  exit 2
fi

failed=0
while IFS= read -r path; do
  relative="${path#"$root"/}"
  case "/$relative" in
    */.git|*/.git/*|*/.env|*/.env.*|*/id_rsa|*/id_ed25519|*.pem|*.key|*.p12|*.pfx|*.sql|*.sql.gz|*.dump|*/project-environment.json|*/cloudpanel-entity-subpath.md|*/setup-required-inputs.md|*/environment-setup-report.md|*_prompt.md|*/docs/internal/*)
      case "$relative" in
        .env.example|*/.env.example) continue ;;
      esac
      echo "FORBIDDEN_ARTIFACT_PATH: $relative"
      failed=1
      ;;
  esac
done < <(find "$root" -mindepth 1 -print)

if [[ "$failed" -ne 0 ]]; then
  echo "Deploy artifact check failed." >&2
  exit 1
fi

echo "Deploy artifact check passed."
