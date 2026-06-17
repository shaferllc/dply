<?php

declare(strict_types=1);

namespace App\Support\Servers\Concerns;

/**
 * Shared bash that fetches + verifies the pinned Vector static binary and drops
 * it at a namespaced path. Used by BOTH the edge agent installer
 * ({@see \App\Support\Servers\VectorLogAgentInstallScripts}) and the aggregator
 * installer ({@see \App\Support\Servers\VectorLogAggregatorInstallScripts}) so the
 * two always install the SAME Vector version (the vector-to-vector protocol link
 * is version-sensitive — see docs/SERVER_LOGS_ADDON.md).
 */
trait InstallsVectorBinary
{
    /**
     * Bash fragment: ensure the pinned Vector binary exists at $binaryPath,
     * re-fetching + sha-verifying only when missing or version-mismatched. Assumes
     * it runs as root. Leaves the binary at $binaryPath; prints nothing on the
     * happy path so callers can still parse `--version` at the end.
     */
    protected function vectorBinaryInstallFragment(string $binaryPath): string
    {
        $version = (string) config('server_logs.vector_version', '0.48.0');
        $sha = trim((string) config('server_logs.vector_sha256', ''));
        $url = "https://packages.timber.io/vector/{$version}/vector-{$version}-__ARCH__.tar.gz";

        $shaCheck = $sha !== ''
            ? <<<BASH
            echo "{$sha}  \$TMP_TGZ" | sha256sum -c - || { echo "vector tarball sha mismatch"; exit 1; }
            BASH
            : 'echo "WARN: vector sha256 not pinned — skipping integrity check (dev only)"';

        return <<<BASH
        export DEBIAN_FRONTEND=noninteractive
        if ! command -v curl >/dev/null 2>&1; then
          apt-get update -y >/dev/null 2>&1 || true
          apt-get install -y curl ca-certificates >/dev/null 2>&1 || true
        fi

        ARCH="\$(uname -m)"
        case "\$ARCH" in
          x86_64|amd64) VEC_ARCH="x86_64-unknown-linux-musl" ;;
          aarch64|arm64) VEC_ARCH="aarch64-unknown-linux-musl" ;;
          *) echo "unsupported arch: \$ARCH"; exit 1 ;;
        esac

        NEED_INSTALL=1
        if [ -x "{$binaryPath}" ]; then
          CUR="\$({$binaryPath} --version 2>/dev/null | awk '{print \$2}' || true)"
          [ "\$CUR" = "{$version}" ] && NEED_INSTALL=0
        fi

        if [ "\$NEED_INSTALL" = "1" ]; then
          TMP_TGZ="\$(mktemp)"
          URL="\$(echo "{$url}" | sed "s/__ARCH__/\$VEC_ARCH/")"
          curl -fsSL --retry 3 -o "\$TMP_TGZ" "\$URL" || { echo "vector download failed: \$URL"; rm -f "\$TMP_TGZ"; exit 1; }
          {$shaCheck}
          TMP_DIR="\$(mktemp -d)"
          tar -xzf "\$TMP_TGZ" -C "\$TMP_DIR" || { echo "vector extract failed"; rm -rf "\$TMP_TGZ" "\$TMP_DIR"; exit 1; }
          VEC_BIN="\$(find "\$TMP_DIR" -type f -name vector -perm -u+x | head -n1)"
          [ -n "\$VEC_BIN" ] || { echo "vector binary not found in tarball"; rm -rf "\$TMP_TGZ" "\$TMP_DIR"; exit 1; }
          install -m 0755 "\$VEC_BIN" "{$binaryPath}"
          rm -rf "\$TMP_TGZ" "\$TMP_DIR"
        fi
        BASH;
    }
}
