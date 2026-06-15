<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Builds the bash lines that install mise on an Ubuntu server and
 * provision per-runtime versions for the deploy user.
 *
 * Per the multi-runtime strategy memo:
 *   - mise manages Node / Python / Ruby / Go.
 *   - PHP is installed via the ondrej/php apt repository instead — mise's
 *     PHP plugin source-builds, which is too slow and unsuitable for a
 *     production FPM server. So this builder ignores `php` if a caller
 *     accidentally asks for it.
 *
 * Pure builder — returns shell lines for the higher-level script
 * assembler ({@see ServerProvisionCommandBuilder}) to splice into the
 * provision script. The actual SSH + execution layer lives elsewhere.
 *
 * Idempotent shape: each apt step probes for prior installation before
 * running, matching the pattern the existing provision builder uses for
 * redis / supervisor / haproxy so re-runs of the bootstrap script are a
 * no-op when mise is already present.
 */
class MiseInstallScriptBuilder
{
    /**
     * Allowed runtime keys. Pinned to the polyglot-five-minus-PHP because
     * mise is the wrong tool for PHP on this platform (see class docblock).
     *
     * @deprecated Prefer {@see supportedRuntimes()} — config-driven catalog.
     */
    public const SUPPORTED_RUNTIMES = ['node', 'python', 'ruby', 'go', 'bun', 'deno', 'java'];

    /**
     * @return list<string>
     */
    public static function supportedRuntimes(): array
    {
        $catalog = config('server_manage.mise_runtimes');
        if (is_array($catalog) && $catalog !== []) {
            return array_values(array_map('strval', array_keys($catalog)));
        }

        return self::SUPPORTED_RUNTIMES;
    }

    /**
     * Bash lines that install mise system-wide via the official apt
     * repository.
     *
     * Idempotent by default ($forceReinstall = false): wraps the install
     * in a `command -v mise` guard so re-runs of the bootstrap are a
     * no-op when mise is already there. When $forceReinstall is true
     * (matches the existing server_provision.force_reinstall flag the
     * composer/php paths honor), the guard is omitted and the apt steps
     * always run.
     *
     * @return list<string>
     */
    public function installLines(bool $forceReinstall = false): array
    {
        // Gate every apt-get behind dply_wait_for_apt_locks (defined in the
        // provisioner preamble). The previous package step's needrestart
        // DPkg::Post-Invoke hook frequently spawns its own short apt-get to
        // check stale services, which races our `apt-get update` for
        // /var/lib/apt/lists/lock and returns exit 100. Wait before both
        // apt calls — needrestart can fire again between update and install.
        $aptSteps = [
            'echo "[dply] installing mise via apt"',
            'install -m 0755 -d /etc/apt/keyrings',
            'curl -fsSL https://mise.jdx.dev/gpg-key.pub | gpg --batch --yes --no-tty --dearmor -o /etc/apt/keyrings/mise-archive-keyring.gpg',
            'chmod a+r /etc/apt/keyrings/mise-archive-keyring.gpg',
            'echo "deb [signed-by=/etc/apt/keyrings/mise-archive-keyring.gpg arch=$(dpkg --print-architecture)] https://mise.jdx.dev/deb stable main" > /etc/apt/sources.list.d/mise.list',
            'dply_wait_for_apt_locks',
            'apt-get update -y',
            'dply_wait_for_apt_locks',
            'apt-get install -y --no-install-recommends mise',
        ];

        if ($forceReinstall) {
            return $aptSteps;
        }

        return array_merge(
            [
                'if command -v mise >/dev/null 2>&1; then',
                '  echo "[dply] mise already installed; skipping installer."',
                'else',
            ],
            array_map(fn (string $line) => '  '.$line, $aptSteps),
            ['fi'],
        );
    }

    /**
     * Bash lines that hook mise into the deploy user's shell so subsequent
     * SSH sessions / systemd ExecStart lines see the runtime executables
     * mise installs (`/home/<user>/.local/share/mise/shims/...`).
     *
     * Adds the activation snippet to ~/.bashrc if not already present,
     * idempotent. Does not source the file; the next login picks it up.
     *
     * @return list<string>
     */
    public function activateForUserLines(string $deployUser): array
    {
        $userArg = escapeshellarg($deployUser);
        $bashrc = '/home/'.$deployUser.'/.bashrc';
        $bashrcArg = escapeshellarg($bashrc);
        $marker = '# dply: mise activation';

        return [
            "if [ -f {$bashrcArg} ] && ! grep -qF '{$marker}' {$bashrcArg}; then",
            "  echo \"[dply] adding mise activation to {$bashrc}\"",
            "  printf '\\n%s\\neval \"\$(mise activate bash)\"\\n' '{$marker}' >> {$bashrcArg}",
            "  chown {$userArg}:{$userArg} {$bashrcArg}",
            'fi',
        ];
    }

    /**
     * Bash lines that install a single mise-managed runtime + version
     * **globally for the deploy user**. Per-site pinning happens at
     * deploy time via `mise use` in the site's working dir; the global
     * default here is what new sites fall back to before the detector
     * has run.
     *
     * Returns an empty array for unsupported runtimes (silent skip
     * matches the rest of the provision builder's defensive style).
     *
     * @return list<string>
     */
    public function installRuntimeForUserLines(string $deployUser, string $runtime, string $version): array
    {
        if (! in_array($runtime, self::supportedRuntimes(), true)) {
            return [];
        }

        $version = trim($version);
        if ($version === '') {
            return [];
        }

        $miseTool = $this->miseToolKey($runtime);
        $userArg = escapeshellarg($deployUser);

        // Force mise to fetch prebuilt release binaries instead of
        // compiling from source. Without this, Python (python-build) and
        // Ruby (ruby-build) default to from-source builds that take
        // 90–240s on a small droplet — for no real benefit on a stock
        // x86_64 server. Node defaults to binary, but setting MISE_NODE_COMPILE=0
        // is harmless and makes the intent explicit. Toggle off via
        // DPLY_MISE_PREFER_BINARY=false to fall back to the legacy
        // compile path.
        //
        // try/catch so unit tests that exercise this builder without a
        // booted framework (no config repository in the container) don't
        // blow up — the prefer-binary default applies in that case.
        //
        // Reference: https://mise.jdx.dev/configuration.html#environment-variables
        try {
            $preferBinary = (bool) config('server_provision.mise_prefer_binary', true);
        } catch (\Throwable) {
            $preferBinary = true;
        }
        $env = $preferBinary
            ? 'MISE_NODE_COMPILE=0 MISE_PYTHON_COMPILE=0 MISE_RUBY_COMPILE=0 PYTHON_BUILD_USE_PREBUILT=1 '
            : '';

        $cmd = escapeshellarg($env."mise use --global {$miseTool}@{$version}");

        return [
            "echo \"[dply] installing {$miseTool}@{$version} globally for {$deployUser} (prefer-binary={$miseTool})\"",
            "sudo -u {$userArg} -H bash -lc {$cmd}",
        ];
    }

    /**
     * Bash lines that install a runtime version for the deploy user and activate
     * it as the global default. Alias of {@see installRuntimeForUserLines} —
     * kept so call sites that name "install version" still get install + activate.
     *
     * @return list<string>
     */
    public function installRuntimeVersionForUserLines(string $deployUser, string $runtime, string $version): array
    {
        return $this->installRuntimeForUserLines($deployUser, $runtime, $version);
    }

    /**
     * Bash lines that uninstall a specific runtime version for the deploy user.
     * Refuses to uninstall the version that's currently pinned as global default —
     * that has to go through the Tools card's "Set as default" flow first.
     *
     * @return list<string>
     */
    public function uninstallRuntimeVersionForUserLines(string $deployUser, string $runtime, string $version): array
    {
        if (! in_array($runtime, self::supportedRuntimes(), true)) {
            return [];
        }
        $version = trim($version);
        if ($version === '') {
            return [];
        }

        $userArg = escapeshellarg($deployUser);
        $miseTool = $this->miseToolKey($runtime);
        $cmd = escapeshellarg("mise uninstall {$miseTool}@{$version}");

        return [
            "echo \"[dply] uninstalling {$miseTool}@{$version} for {$deployUser}\"",
            "sudo -u {$userArg} -H bash -lc {$cmd}",
        ];
    }

    /**
     * Bash lines that set a runtime version as the deploy user's global default.
     * If the version isn't installed, mise will install it as a side effect —
     * making this the right surface for "switch default to vX.Y.Z" with a single
     * click. Same shape as installRuntimeForUserLines() (which is the provision-
     * time entry point), kept separate so the call site is self-documenting.
     *
     * @return list<string>
     */
    public function setRuntimeDefaultForUserLines(string $deployUser, string $runtime, string $version): array
    {
        return $this->installRuntimeForUserLines($deployUser, $runtime, $version);
    }

    /**
     * Map our canonical runtime keys onto mise's plugin names. The
     * mapping is mostly identity but Go's plugin is `go` while detection
     * already uses `go` too — kept here so future divergence is one-line.
     */
    private function miseToolKey(string $runtime): string
    {
        return match ($runtime) {
            'node' => 'node',
            'python' => 'python',
            'ruby' => 'ruby',
            'go' => 'go',
            'bun' => 'bun',
            'deno' => 'deno',
            'java' => 'java',
            default => $runtime,
        };
    }

    /**
     * Force mise to fetch prebuilt release binaries instead of compiling from
     * source. Extracted so installRuntimeForUserLines and its install-only
     * sibling agree without duplication.
     */
    private function preferBinaryEnv(): string
    {
        try {
            $preferBinary = (bool) config('server_provision.mise_prefer_binary', true);
        } catch (\Throwable) {
            $preferBinary = true;
        }

        return $preferBinary
            ? 'MISE_NODE_COMPILE=0 MISE_PYTHON_COMPILE=0 MISE_RUBY_COMPILE=0 PYTHON_BUILD_USE_PREBUILT=1 '
            : '';
    }
}
