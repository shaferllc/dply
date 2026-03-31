<?php

namespace App\Services\Servers;

/**
 * Standalone guest Python script under resources/server-scripts: deploy path and install bash.
 */
class ServerMetricsGuestScript
{
    public function localPath(): string
    {
        return resource_path('server-scripts/server-metrics-snapshot.py');
    }

    /**
     * Path on the guest relative to $HOME (used in docs / meta).
     */
    public function remoteRelativePath(): string
    {
        return (string) config('server_metrics.guest_script.relative_path', '.dply/bin/server-metrics-snapshot.py');
    }

    /**
     * SHA-256 of the bundled guest Python file (used to detect stale installs on the server).
     */
    public function bundledSha256(): string
    {
        $local = $this->localPath();
        if (! is_readable($local)) {
            throw new \RuntimeException('Missing guest metrics script at '.$local);
        }

        $hash = hash_file('sha256', $local);
        if ($hash === false) {
            throw new \RuntimeException('Could not hash guest metrics script at '.$local);
        }

        return $hash;
    }

    /**
     * Deploy only: write resources/server-scripts copy to ~/.dply/bin/ (no apt). Used for auto-upgrade.
     */
    public function guestScriptDeployOnlyScript(): string
    {
        return str_replace("\r\n", "\n", $this->guestScriptDeployBashFragment());
    }

    /**
     * Bash that installs python3-minimal (apt) and writes the script to ~/.dply/bin/.
     */
    public function monitoringPrerequisitesInstallScript(): string
    {
        /*
         * Apt: stay quiet when python3 is already present — fewer stdout chunks (TaskRunner logs each
         * chunk; repeating "python3 is already installed" looked like an infinite loop).
         *
         * Deploy: write base64 to a temp file with a short-line heredoc, then `base64 -d` to the guest
         * script path. Avoids a single megabyte-long shell line and fragile stdin heredocs to `base64 -d`.
         */
        $apt = <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
if ! command -v python3 >/dev/null 2>&1; then
  (sudo -n apt-get update && sudo -n apt-get install -y python3-minimal) 2>&1 \
    || (apt-get update && apt-get install -y python3-minimal) 2>&1
fi
BASH;

        return str_replace("\r\n", "\n", rtrim($apt)."\n".$this->guestScriptDeployBashFragment());
    }

    /**
     * mkdir + base64 decode into ~/.dply/bin/server-metrics-snapshot.py.
     */
    protected function guestScriptDeployBashFragment(): string
    {
        $local = $this->localPath();
        if (! is_readable($local)) {
            throw new \RuntimeException('Missing guest metrics script at '.$local);
        }

        $payload = (string) file_get_contents($local);
        $b64 = base64_encode($payload);
        $b64Lines = rtrim(chunk_split($b64, 76, "\n"));
        $expectedSha = $this->bundledSha256();

        $deploy = <<<'BASH'
mkdir -p "$HOME/.dply/bin"
TMP_B64="$(mktemp)"
cat <<'DPLY_B64_FILE' > "$TMP_B64"
BASH;
        $deploy .= "\n".$b64Lines."\n";
        $deploy .= <<<'BASH'
DPLY_B64_FILE
base64 -d "$TMP_B64" > "$HOME/.dply/bin/server-metrics-snapshot.py" || { rm -f "$TMP_B64"; exit 1; }
chmod 755 "$HOME/.dply/bin/server-metrics-snapshot.py"
test -s "$HOME/.dply/bin/server-metrics-snapshot.py" || { echo "guest metrics script is empty after deploy"; rm -f "$TMP_B64"; exit 1; }
ACTUAL_SHA="$(sha256sum "$HOME/.dply/bin/server-metrics-snapshot.py" | awk '{print $1}')"
test "$ACTUAL_SHA" = "__EXPECTED_SHA__" || { echo "guest metrics script sha mismatch: $ACTUAL_SHA"; rm -f "$TMP_B64"; exit 1; }
rm -f "$TMP_B64"
echo "Installed Dply metrics script at $HOME/.dply/bin/server-metrics-snapshot.py"
BASH;

        return str_replace("\r\n", "\n", str_replace('__EXPECTED_SHA__', $expectedSha, $deploy));
    }

    /**
     * Python source without shebang (for stdin fallback heredoc).
     */
    public function pythonBodyForInlineFallback(): string
    {
        $raw = (string) file_get_contents($this->localPath());
        $stripped = preg_replace('/^#!.*\r?\n/', '', $raw, 1);

        return is_string($stripped) ? $stripped : $raw;
    }
}
