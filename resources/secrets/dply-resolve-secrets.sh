#!/usr/bin/env bash
#
# dply-resolve-secrets.sh — on-box resolver for Tier 3+ external secrets.
#
# Runs ON THE SERVER (never on dply's control plane) so the secret VALUES are
# fetched store -> box directly and dply never sees them. Reads a manifest of
# {env_key, directive, driver, reference, config} produced by
# App\Services\Sites\OnBoxSecretManifestBuilder, fetches each value from the
# customer's store using credentials present on the box, and rewrites the
# matching directive placeholder in the target .env in place.
#
# Usage: dply-resolve-secrets.sh <manifest.json> <target.env>
#
# Requires: jq, and per driver: curl (vault/doppler) or the AWS CLI (aws_sm).
# The manifest may carry a store access token; it is deleted on exit.
set -euo pipefail

MANIFEST="${1:?manifest path required}"
ENV_FILE="${2:?target .env path required}"

cleanup() { rm -f "$MANIFEST"; }
trap cleanup EXIT

command -v jq >/dev/null 2>&1 || { echo "dply-resolve-secrets: jq is required" >&2; exit 1; }
[ -f "$MANIFEST" ] || { echo "dply-resolve-secrets: manifest not found: $MANIFEST" >&2; exit 1; }
[ -f "$ENV_FILE" ] || { echo "dply-resolve-secrets: env file not found: $ENV_FILE" >&2; exit 1; }

# Resolve one secret to stdout based on its driver.
fetch_secret() {
  local driver="$1" reference="$2" config="$3"
  local path field
  path="${reference%%#*}"
  field=""
  case "$reference" in *\#*) field="${reference#*#}";; esac

  case "$driver" in
    vault)
      local endpoint token namespace resp data
      endpoint="$(printf '%s' "$config" | jq -r '.endpoint // empty')"
      token="$(printf '%s' "$config" | jq -r '.token // empty')"
      namespace="$(printf '%s' "$config" | jq -r '.namespace // empty')"
      [ -n "$endpoint" ] && [ -n "$token" ] || { echo "vault: endpoint/token missing" >&2; return 1; }
      resp="$(curl -fsS -H "X-Vault-Token: $token" ${namespace:+-H "X-Vault-Namespace: $namespace"} "${endpoint%/}/v1/${path#/}")"
      # KV v2 nests under .data.data; KV v1 under .data
      data="$(printf '%s' "$resp" | jq -c '.data.data // .data')"
      if [ -n "$field" ]; then printf '%s' "$data" | jq -r --arg f "$field" '.[$f]'
      else printf '%s' "$data" | jq -r 'to_entries | if length==1 then .[0].value else error("multiple fields; add #field") end'; fi
      ;;
    doppler)
      local token project config_name resp
      token="$(printf '%s' "$config" | jq -r '.token // empty')"
      project="$(printf '%s' "$config" | jq -r '.project // empty')"
      config_name="$(printf '%s' "$config" | jq -r '.config // empty')"
      [ -n "$token" ] || { echo "doppler: token missing" >&2; return 1; }
      resp="$(curl -fsS -u "$token:" "https://api.doppler.com/v3/configs/config/secret?project=${project}&config=${config_name}&name=${path}")"
      printf '%s' "$resp" | jq -r '.value.raw'
      ;;
    aws_sm)
      local region out
      region="$(printf '%s' "$config" | jq -r '.region // empty')"
      [ -n "$region" ] || { echo "aws_sm: region missing" >&2; return 1; }
      # Uses the box's own credential chain (instance IAM) — no keys shipped.
      out="$(aws secretsmanager get-secret-value --region "$region" --secret-id "$path" --query SecretString --output text)"
      if [ -n "$field" ]; then printf '%s' "$out" | jq -r --arg f "$field" '.[$f]'
      else printf '%s' "$out"; fi
      ;;
    *)
      echo "dply-resolve-secrets: unknown driver '$driver'" >&2; return 1 ;;
  esac
}

# Replace KEY=<directive> with KEY="<value>" in the env file (value escaped).
write_env() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp)"
  # Escape backslashes and double quotes for a double-quoted .env value.
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  awk -v k="$key" -v v="$value" '
    $0 ~ "^"k"=" { print k"=\"" v "\""; next }
    { print }
  ' "$ENV_FILE" > "$tmp"
  chmod 600 "$tmp"
  mv "$tmp" "$ENV_FILE"
}

count="$(jq '.secrets | length' "$MANIFEST")"
i=0
while [ "$i" -lt "$count" ]; do
  entry="$(jq -c ".secrets[$i]" "$MANIFEST")"
  env_key="$(printf '%s' "$entry" | jq -r '.env_key')"
  driver="$(printf '%s' "$entry" | jq -r '.driver')"
  reference="$(printf '%s' "$entry" | jq -r '.reference')"
  config="$(printf '%s' "$entry" | jq -c '.config')"

  value="$(fetch_secret "$driver" "$reference" "$config")"
  write_env "$env_key" "$value"
  echo "dply-resolve-secrets: resolved $env_key from $driver"
  i=$((i + 1))
done

echo "dply-resolve-secrets: resolved $count secret(s) on-box"
