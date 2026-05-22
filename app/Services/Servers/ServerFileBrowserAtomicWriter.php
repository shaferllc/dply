<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\FileBrowserPathPolicy;

/**
 * Atomic write for the site file browser: re-stat + sha256 against the
 * client's expected pre-image, write to a sibling tempfile, copy mode and
 * ownership from the original, then `mv` into place. The whole sequence runs
 * as one SSH command so a half-finished write can't leave a corrupt file.
 *
 * Returns FileBrowserWriteResult; check ->conflict() before relying on
 * ->newSha256 / ->newMtime.
 */
class ServerFileBrowserAtomicWriter
{
    public function write(
        Server $server,
        string $path,
        string $expectedSha256,
        int $expectedMtime,
        string $newContent,
        string $loginUser,
    ): FileBrowserWriteResult {
        $path = FileBrowserPathPolicy::normalize($path);
        $target = escapeshellarg($path);
        $tmp = escapeshellarg($path.'.dply-tmp.'.bin2hex(random_bytes(6)));
        $timeout = max(60, (int) config('server_file_browser.ssh_timeout_seconds', 30) * 2);

        $heredoc = $this->heredoc($newContent);

        // The sequence:
        //   1. stat -c '%Y' + sha256sum: capture current mtime/hash so we can
        //      diff against the client's expectation.
        //   2. write tempfile via heredoc (single quotes, no shell expansion).
        //   3. chmod/chown --reference to preserve the original file's perms.
        //   4. mv tempfile target (atomic rename(2)).
        //   5. stat + sha256sum new file for the response.
        //   6. echo a sentinel envelope so we can parse the multipart output.
        $script = <<<SH
set -u
target={$target}
tmp={$tmp}
if [ ! -e "\$target" ]; then
    echo __DPLY_WRITE_ERROR__::MISSING
    exit 0
fi
current_mtime=\$(stat -c '%Y' -- "\$target" 2>/dev/null)
current_sha=\$(sha256sum -- "\$target" 2>/dev/null | awk '{print \$1}')
echo __DPLY_WRITE_CURRENT__::\$current_mtime::\$current_sha
expected_mtime={$expectedMtime}
expected_sha={$this->shellQuote($expectedSha256)}
if [ "\$current_mtime" != "\$expected_mtime" ] || [ "\$current_sha" != "\$expected_sha" ]; then
    echo __DPLY_WRITE_ERROR__::CONFLICT
    exit 0
fi
{$heredoc}
if [ \$? -ne 0 ]; then
    rm -f -- "\$tmp"
    echo __DPLY_WRITE_ERROR__::TMP_WRITE
    exit 0
fi
chmod --reference="\$target" -- "\$tmp" 2>/dev/null
chown --reference="\$target" -- "\$tmp" 2>/dev/null
mv -- "\$tmp" "\$target"
if [ \$? -ne 0 ]; then
    rm -f -- "\$tmp"
    echo __DPLY_WRITE_ERROR__::MV_FAILED
    exit 0
fi
new_mtime=\$(stat -c '%Y' -- "\$target" 2>/dev/null)
new_sha=\$(sha256sum -- "\$target" 2>/dev/null | awk '{print \$1}')
echo __DPLY_WRITE_OK__::\$new_mtime::\$new_sha
SH;

        $ssh = $this->connect($server, $loginUser);
        try {
            $output = $ssh->exec($script, $timeout);
        } finally {
            $ssh->disconnect();
        }

        return $this->parseResult($output);
    }

    protected function parseResult(string $output): FileBrowserWriteResult
    {
        $error = $this->findLine($output, '__DPLY_WRITE_ERROR__::');
        if ($error !== null) {
            return new FileBrowserWriteResult(
                ok: false,
                conflictReason: $error,
                newSha256: '',
                newMtime: 0,
            );
        }

        $ok = $this->findLine($output, '__DPLY_WRITE_OK__::');
        if ($ok === null) {
            return new FileBrowserWriteResult(
                ok: false,
                conflictReason: 'UNKNOWN',
                newSha256: '',
                newMtime: 0,
            );
        }

        $parts = explode('::', $ok);
        $newMtime = (int) ($parts[0] ?? 0);
        $newSha = $parts[1] ?? '';

        return new FileBrowserWriteResult(
            ok: true,
            conflictReason: null,
            newSha256: $newSha,
            newMtime: $newMtime,
        );
    }

    protected function findLine(string $output, string $marker): ?string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        foreach ($lines as $line) {
            if (str_starts_with($line, $marker)) {
                return substr($line, strlen($marker));
            }
        }

        return null;
    }

    protected function connect(Server $server, string $loginUser): SshConnection
    {
        $role = $loginUser === 'root'
            ? SshConnection::ROLE_RECOVERY
            : SshConnection::ROLE_OPERATIONAL;

        return new SshConnection($server, $loginUser, $role);
    }

    /**
     * Build a heredoc that writes $content to $tmp without shell interpolation.
     * Uses a random unique sentinel so content matching the sentinel is safe.
     */
    protected function heredoc(string $content): string
    {
        $sentinel = 'DPLY_EOF_'.bin2hex(random_bytes(6));

        return "cat > \"\$tmp\" <<'{$sentinel}'\n".$content."\n{$sentinel}";
    }

    protected function shellQuote(string $s): string
    {
        return "'".str_replace("'", "'\\''", $s)."'";
    }
}
