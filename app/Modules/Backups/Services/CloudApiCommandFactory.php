<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Models\BackupConfiguration;
use InvalidArgumentException;

/**
 * Shell scripts that move a dump between a server and an OAuth cloud drive
 * (Dropbox, Google Drive).
 *
 * These differ from both other transports. S3 presigns a URL; SFTP/FTP/Rclone
 * speak a file protocol. A cloud drive needs a bearer token on every HTTPS call
 * and answers in JSON, so the work splits: {@see CloudApiTokenResolver} mints
 * the token in the control plane, and this class builds the script the server
 * runs with it. Google's `client_secret` therefore never reaches the server —
 * only a short-lived access token does.
 *
 * The script goes to the server as a mode-600 file rather than an argv string,
 * which keeps the token out of `ps` and the shell history, and sidesteps a
 * layer of quoting.
 *
 * Chunking is not optional: Dropbox's single-shot endpoint caps at 150 MB and
 * real database dumps pass that routinely, so uploads always run through an
 * upload session. Google's resumable endpoint handles size natively.
 *
 * Pure by construction — no HTTP, no SSH — so the script text is unit testable.
 */
final class CloudApiCommandFactory
{
    /** Providers this factory can move bytes for. */
    public const SUPPORTED = [
        BackupConfiguration::PROVIDER_DROPBOX,
        BackupConfiguration::PROVIDER_GOOGLE_DRIVE,
    ];

    /**
     * Dropbox upload-session chunk size. Well under the 150 MB single-request
     * ceiling, and small enough that one flaky chunk is cheap to lose.
     */
    private const DROPBOX_CHUNK_BYTES = 64 * 1024 * 1024;

    public static function supports(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED, true);
    }

    /**
     * Where the dump will live. Dropbox addresses files by path; Google Drive
     * assigns an opaque id at upload time, so for Drive this is only the display
     * name and the real handle comes back in the upload's JSON.
     */
    public function objectPath(BackupConfiguration $configuration, string $key): string
    {
        $config = $this->configFor($configuration);

        if ($configuration->provider === BackupConfiguration::PROVIDER_GOOGLE_DRIVE) {
            return basename($key);
        }

        $base = $this->normalizeDropboxFolder((string) ($config['path'] ?? ''));

        return $base.'/'.ltrim($key, '/');
    }

    /**
     * @return array{command: string, files: array<string, string>}
     */
    public function uploadCommand(
        BackupConfiguration $configuration,
        string $accessToken,
        string $localPath,
        string $objectPath,
        string $scratchId,
    ): array {
        $script = match ($configuration->provider) {
            BackupConfiguration::PROVIDER_DROPBOX => $this->dropboxUploadScript($accessToken, $localPath, $objectPath),
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => $this->driveUploadScript($configuration, $accessToken, $localPath, $objectPath),
            default => throw new InvalidArgumentException('Unsupported cloud-drive provider: '.$configuration->provider),
        };

        return $this->asScript($script, $scratchId);
    }

    /**
     * @return array{command: string, files: array<string, string>}
     */
    public function downloadCommand(
        BackupConfiguration $configuration,
        string $accessToken,
        string $handle,
        string $localPath,
        string $scratchId,
    ): array {
        $script = match ($configuration->provider) {
            BackupConfiguration::PROVIDER_DROPBOX => $this->dropboxDownloadScript($accessToken, $handle, $localPath),
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => $this->driveDownloadScript($accessToken, $handle, $localPath),
            default => throw new InvalidArgumentException('Unsupported cloud-drive provider: '.$configuration->provider),
        };

        return $this->asScript($script, $scratchId);
    }

    /**
     * @return array{command: string, files: array<string, string>}
     */
    public function deleteCommand(
        BackupConfiguration $configuration,
        string $accessToken,
        string $handle,
        string $scratchId,
    ): array {
        $script = match ($configuration->provider) {
            BackupConfiguration::PROVIDER_DROPBOX => sprintf(
                "curl --silent --show-error --fail-with-body -X POST %s -H %s -H 'Content-Type: application/json' --data %s\n",
                escapeshellarg('https://api.dropboxapi.com/2/files/delete_v2'),
                escapeshellarg('Authorization: Bearer '.$accessToken),
                escapeshellarg(json_encode(['path' => $handle], JSON_THROW_ON_ERROR)),
            ),
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => sprintf(
                "curl --silent --show-error --fail-with-body -X DELETE -H %s %s\n",
                escapeshellarg('Authorization: Bearer '.$accessToken),
                escapeshellarg('https://www.googleapis.com/drive/v3/files/'.rawurlencode($handle)),
            ),
            default => throw new InvalidArgumentException('Unsupported cloud-drive provider: '.$configuration->provider),
        };

        return $this->asScript($script, $scratchId);
    }

    /**
     * The handle to persist for a finished upload. Dropbox keeps the path it was
     * given; Google Drive hands back an id in the upload response, and losing it
     * would orphan the file beyond any later download or prune.
     */
    public function handleFromUploadOutput(BackupConfiguration $configuration, string $output, string $objectPath): string
    {
        if ($configuration->provider !== BackupConfiguration::PROVIDER_GOOGLE_DRIVE) {
            return $objectPath;
        }

        $decoded = json_decode(trim($output), true);
        $id = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';

        if ($id === '') {
            throw new \RuntimeException('Google Drive upload did not return a file id.');
        }

        return $id;
    }

    /** @param  array<string, string>  $files */
    public function cleanupCommand(array $files): string
    {
        if ($files === []) {
            return '';
        }

        return 'rm -f '.implode(' ', array_map(escapeshellarg(...), array_keys($files)));
    }

    /**
     * Dropbox upload via a session, always. `split` chops the dump into chunks;
     * start/append/finish carry the offset. session_id is pulled out with grep
     * rather than jq, which is not installed on a stock box.
     */
    private function dropboxUploadScript(string $token, string $localPath, string $objectPath): string
    {
        $auth = escapeshellarg('Authorization: Bearer '.$token);
        $chunk = self::DROPBOX_CHUNK_BYTES;
        $startArg = $this->headerJson(['close' => false]);
        $commitArg = $this->headerJson([
            'path' => $objectPath,
            'mode' => 'overwrite',
            'mute' => true,
        ]);
        $src = escapeshellarg($localPath);

        return <<<BASH
set -euo pipefail
SRC={$src}
WORK="\$(mktemp -d)"
trap 'rm -rf "\$WORK"' EXIT

split -b {$chunk} -- "\$SRC" "\$WORK/part."

OFFSET=0
SESSION=""
for PART in "\$WORK"/part.*; do
  SIZE="\$(wc -c < "\$PART" | tr -d '[:space:]')"
  if [ -z "\$SESSION" ]; then
    RESP="\$(curl --silent --show-error --fail-with-body -X POST \\
      https://content.dropboxapi.com/2/files/upload_session/start \\
      -H {$auth} \\
      -H "Dropbox-API-Arg: {$startArg}" \\
      -H 'Content-Type: application/octet-stream' \\
      --data-binary @"\$PART")"
    SESSION="\$(printf '%s' "\$RESP" | grep -o '"session_id": *"[^"]*"' | head -n1 | sed 's/.*"\\([^"]*\\)"\$/\\1/')"
    if [ -z "\$SESSION" ]; then
      echo "Dropbox did not return a session id: \$RESP" >&2
      exit 1
    fi
  else
    curl --silent --show-error --fail-with-body -X POST \\
      https://content.dropboxapi.com/2/files/upload_session/append_v2 \\
      -H {$auth} \\
      -H "Dropbox-API-Arg: {\\"cursor\\":{\\"session_id\\":\\"\$SESSION\\",\\"offset\\":\$OFFSET},\\"close\\":false}" \\
      -H 'Content-Type: application/octet-stream' \\
      --data-binary @"\$PART" > /dev/null
  fi
  OFFSET=\$((OFFSET + SIZE))
done

curl --silent --show-error --fail-with-body -X POST \\
  https://content.dropboxapi.com/2/files/upload_session/finish \\
  -H {$auth} \\
  -H "Dropbox-API-Arg: {\\"cursor\\":{\\"session_id\\":\\"\$SESSION\\",\\"offset\\":\$OFFSET},\\"commit\\":{$commitArg}}" \\
  -H 'Content-Type: application/octet-stream' \\
  --data-binary @/dev/null > /dev/null

BASH;
    }

    private function dropboxDownloadScript(string $token, string $path, string $localPath): string
    {
        return sprintf(
            "set -euo pipefail\ncurl --silent --show-error --fail-with-body -X POST %s -H %s -H %s --output %s\n",
            escapeshellarg('https://content.dropboxapi.com/2/files/download'),
            escapeshellarg('Authorization: Bearer '.$token),
            // escapeshellarg, NOT headerJson: that escapes quotes for embedding
            // inside a double-quoted shell string, and this is a standalone
            // argument. Getting it wrong sends Dropbox literal backslashes and
            // it rejects the request. escapeshellarg also survives an apostrophe
            // in a folder name, which single quotes would not.
            escapeshellarg('Dropbox-API-Arg: '.json_encode(['path' => $path], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            escapeshellarg($localPath),
        );
    }

    /**
     * Google Drive resumable upload: initiate to get a session URL from the
     * Location header, then PUT the file at it. Resumable rather than multipart
     * because multipart buffers the whole dump in one request.
     */
    private function driveUploadScript(
        BackupConfiguration $configuration,
        string $token,
        string $localPath,
        string $name,
    ): string {
        $config = $this->configFor($configuration);
        $folder = trim((string) ($config['folder_id'] ?? ''));

        $metadata = ['name' => $name];
        if ($folder !== '') {
            $metadata['parents'] = [$folder];
        }

        $auth = escapeshellarg('Authorization: Bearer '.$token);
        $meta = escapeshellarg((string) json_encode($metadata, JSON_THROW_ON_ERROR));

        return <<<BASH
            set -euo pipefail
            HEADERS="\$(mktemp)"
            trap 'rm -f "\$HEADERS"' EXIT

            curl --silent --show-error --fail-with-body -X POST \\
              'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true' \\
              -H {$auth} \\
              -H 'Content-Type: application/json; charset=UTF-8' \\
              --data {$meta} \\
              -D "\$HEADERS" -o /dev/null

            LOCATION="\$(grep -i '^location:' "\$HEADERS" | tail -n1 | sed 's/^[Ll]ocation: *//' | tr -d '\\r\\n')"
            if [ -z "\$LOCATION" ]; then
              echo 'Google Drive did not return a resumable session URL.' >&2
              exit 1
            fi

            # stdout is the file metadata JSON — the control plane reads the id from it.
            curl --silent --show-error --fail-with-body -X PUT \\
              -H {$auth} \\
              --upload-file {$this->q($localPath)} \\
              "\$LOCATION"
            BASH."\n";
    }

    private function driveDownloadScript(string $token, string $fileId, string $localPath): string
    {
        return sprintf(
            "set -euo pipefail\ncurl --silent --show-error --fail-with-body -L -H %s --output %s %s\n",
            escapeshellarg('Authorization: Bearer '.$token),
            escapeshellarg($localPath),
            escapeshellarg('https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?alt=media&supportsAllDrives=true'),
        );
    }

    /**
     * Wrap a script as a mode-600 file plus the command that runs it. Bearer
     * tokens live in the file, never in argv.
     *
     * @return array{command: string, files: array<string, string>}
     */
    private function asScript(string $script, string $scratchId): array
    {
        $path = $this->scratchPath($scratchId);

        return [
            'command' => 'bash '.escapeshellarg($path),
            'files' => [$path => $script],
        ];
    }

    /** JSON for a Dropbox-API-Arg header, safe to embed inside a double-quoted shell string. */
    private function headerJson(array $value): string
    {
        $json = (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $json);
    }

    /** Dropbox paths are absolute from the app root, with no trailing slash. */
    private function normalizeDropboxFolder(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path)), static fn (string $p): bool => $p !== ''));

        return $parts === [] ? '' : '/'.implode('/', $parts);
    }

    /** @return array<string, mixed> */
    private function configFor(BackupConfiguration $configuration): array
    {
        return $configuration->config ?? [];
    }

    private function scratchPath(string $scratchId): string
    {
        // A traversal here would write a bearer token to an attacker-chosen path.
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $scratchId) ?? '';

        return '/tmp/dply-cloud-'.($safe === '' ? 'dply' : $safe).'.sh';
    }

    private function q(string $value): string
    {
        return escapeshellarg($value);
    }
}
