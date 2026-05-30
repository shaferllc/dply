#!/usr/bin/env bash
#
# dply CLI installer — downloads the CLI package from your dply instance.
#
#   curl -fsSL https://your-dply.example/cli/install.sh | bash -s -- --login
#
# The CLI is hosted by dply at /cli/dply-cli.tgz (not npm, unless you opt in).
#
# Environment:
#   DPLY_BASE_URL              dply web/API origin
#   DPLY_CLI_INSTALL_METHOD    tarball | npm | auto (injected default: __DPLY_CLI_INSTALL_METHOD__)
#   DPLY_CLI_NPM_PUBLISHED     1 when @dply/cli is on npm (injected: __DPLY_CLI_NPM_PUBLISHED__)
#
set -euo pipefail

NODE_MIN_MAJOR=18
DEFAULT_BASE_URL="__DPLY_DEFAULT_BASE_URL__"
DEFAULT_INSTALL_METHOD="__DPLY_CLI_INSTALL_METHOD__"
NPM_PUBLISHED_FLAG="__DPLY_CLI_NPM_PUBLISHED__"
NPM_PACKAGE="${DPLY_CLI_NPM_PACKAGE:-@dply/cli}"
DO_LOGIN=0
BASE_URL="${DPLY_BASE_URL:-$DEFAULT_BASE_URL}"

if [[ "$DEFAULT_BASE_URL" == __DPLY_* ]]; then
  DEFAULT_BASE_URL=""
fi

if [[ "$DEFAULT_INSTALL_METHOD" == __DPLY_* ]]; then
  DEFAULT_INSTALL_METHOD="tarball"
fi

if [[ "$NPM_PUBLISHED_FLAG" == __DPLY_* ]]; then
  NPM_PUBLISHED_FLAG="0"
fi

INSTALL_METHOD="${DPLY_CLI_INSTALL_METHOD:-$DEFAULT_INSTALL_METHOD}"

if [[ -z "$BASE_URL" || "$BASE_URL" == __DPLY_* ]]; then
  BASE_URL=""
fi

usage() {
  cat <<'EOF'
dply CLI installer

The CLI package is downloaded from your dply instance (/cli/dply-cli.tgz).
Requires Node.js 18+ and npm (used only to install the downloaded package globally).

Usage:
  curl -fsSL https://<your-dply>/cli/install.sh | bash -s -- --login

Options:
  --base-url URL   dply instance URL (auto-filled when served from dply)
  --login          Run `dply login` when install finishes
  --no-login       Skip login after install
  --method METHOD  tarball | npm | auto
  -h, --help       Show this help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url)
      BASE_URL="${2:-}"
      shift 2
      ;;
    --login)
      DO_LOGIN=1
      shift
      ;;
    --no-login)
      DO_LOGIN=0
      shift
      ;;
    --method)
      INSTALL_METHOD="${2:-tarball}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "dply install: unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

info() { printf '==> %s\n' "$*"; }
warn() { printf 'warning: %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

need_cmd curl

if ! command -v node >/dev/null 2>&1; then
  die "Node.js ${NODE_MIN_MAJOR}+ is required. Install from https://nodejs.org/ then re-run this script."
fi

NODE_MAJOR="$(node -p "process.versions.node.split('.')[0]")"
if [[ "$NODE_MAJOR" -lt "$NODE_MIN_MAJOR" ]]; then
  die "Node.js ${NODE_MIN_MAJOR}+ is required (found $(node -v))."
fi

need_cmd npm

install_via_tarball() {
  local url base
  base="${BASE_URL:-${DEFAULT_BASE_URL:-}}"
  if [[ -z "$base" ]]; then
    die "No dply base URL. Pipe from your instance (curl …/cli/install.sh | bash) or pass --base-url."
  fi
  base="${base%/}"
  url="${base}/cli/dply-cli.tgz"
  info "Downloading CLI from ${url}…"
  local tmp tgz
  tmp="$(mktemp -d)"
  tgz="${tmp}/dply-cli.tgz"
  if ! curl -fsSL "$url" -o "$tgz"; then
    rm -rf "$tmp"
    die "Could not download CLI package from ${url}"
  fi
  info "Installing globally via npm…"
  if ! npm install -g "$tgz"; then
    rm -rf "$tmp"
    die "npm could not install the CLI package. Ensure Node.js ${NODE_MIN_MAJOR}+ and npm are working, then retry."
  fi
  rm -rf "$tmp"
}

install_via_npm_registry() {
  info "Installing ${NPM_PACKAGE} from npm…"
  npm install -g "${NPM_PACKAGE}@latest"
}

npm_published() {
  [[ "$NPM_PUBLISHED_FLAG" == "1" || "$NPM_PUBLISHED_FLAG" == "true" ]]
}

installed=0
case "$INSTALL_METHOD" in
  tarball)
    install_via_tarball && installed=1
    ;;
  npm)
    install_via_npm_registry && installed=1
    ;;
  auto)
    if npm_published && install_via_npm_registry; then
      installed=1
    elif install_via_tarball; then
      installed=1
    fi
    ;;
  *)
    die "Unknown install method: ${INSTALL_METHOD} (use tarball, npm, or auto)"
    ;;
esac

if [[ "$installed" -ne 1 ]]; then
  die "CLI install failed."
fi

if ! command -v dply >/dev/null 2>&1; then
  npm_bin="$(npm prefix -g 2>/dev/null || true)/bin"
  if [[ -d "$npm_bin" ]]; then
    export PATH="${npm_bin}:${PATH}"
  fi
fi

command -v dply >/dev/null 2>&1 || die "Install finished but \`dply\` is not on PATH. Add $(npm prefix -g)/bin to your shell profile."

info "Installed: $(dply --version 2>/dev/null || echo 'dply CLI')"

login_base="${BASE_URL:-${DEFAULT_BASE_URL:-}}"

seed_cli_base_url() {
  local base="${1%/}"
  local cfg="${HOME}/.dply/config.json"
  mkdir -p "${HOME}/.dply"
  node -e "
    const fs = require('fs');
    const path = process.argv[1];
    const baseUrl = process.argv[2];
    let cfg = {};
    try { cfg = JSON.parse(fs.readFileSync(path, 'utf8')); } catch {}
    if (!cfg.baseUrl) {
      cfg.baseUrl = baseUrl;
      fs.writeFileSync(path, JSON.stringify(cfg, null, 2), { mode: 0o600 });
    }
  " "$cfg" "$base"
}

if [[ -n "$login_base" ]]; then
  seed_cli_base_url "${login_base%/}"
fi

if [[ "$DO_LOGIN" -eq 1 ]]; then
  if [[ -z "$login_base" ]]; then
    warn "Skipping login — pass --base-url or set DPLY_BASE_URL."
  else
    info "Starting device login (browser will open)…"
    dply login --base-url "${login_base%/}"
  fi
elif [[ -t 0 && -t 1 && -n "$login_base" ]]; then
  printf '\nInstance URL saved. Run `dply login` (or `dply login --base-url %s`).\n' "${login_base%/}"
else
  printf '\nNext: dply login --base-url <your-dply-url>\n'
fi
