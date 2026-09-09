<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Models\BackupConfiguration;
use InvalidArgumentException;

/**
 * Shell commands that move a dump between a server and a non-S3 destination
 * (SFTP, FTP, Rclone).
 *
 * S3-compatible destinations never need this: the control plane presigns a URL
 * and the server curls the bytes straight up. SFTP/FTP/Rclone have no
 * presigning, so the credentials have to reach the server — which is exactly
 * why this class exists as a pure command builder. Every secret is passed
 * through a mode-600 temp file (curl `--config`, rclone `--config`) rather than
 * argv, so it never appears in `ps`, the server's shell history, or a dply
 * console transcript. The caller writes those files, runs the command, and
 * removes them.
 *
 * Pure by construction: no SSH, no filesystem, no network. That makes the part
 * most likely to be wrong — quoting, URL assembly, path joining — unit
 * testable without a live endpoint.
 */
final class FileTransportCommandFactory
{
    /** Providers this factory can move bytes for. */
    public const SUPPORTED = [
        BackupConfiguration::PROVIDER_SFTP,
        BackupConfiguration::PROVIDER_FTP,
        BackupConfiguration::PROVIDER_RCLONE,
    ];

    public static function supports(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED, true);
    }

    /**
     * The remote location a dump will land at, recorded on the backup row so a
     * later download or prune can find it again. Always a POSIX-style path
     * relative to the destination root, never a URL — the credentials that turn
     * it into a URL live on the configuration, not the artifact.
     */
    public function objectPath(BackupConfiguration $configuration, string $key): string
    {
        $base = $this->normalizeBasePath($this->basePathFor($configuration));
        $key = ltrim($key, '/');

        return $base === '' ? $key : $base.'/'.$key;
    }

    /**
     * Upload `$localPath` (already on the server) to `$objectPath` on the
     * destination.
     *
     * @return array{command: string, files: array<string, string>}
     *                                                              `files` maps an absolute temp path on the server to the
     *                                                              content that must be written there (mode 600) first.
     */
    public function uploadCommand(
        BackupConfiguration $configuration,
        string $localPath,
        string $objectPath,
        string $scratchId,
    ): array {
        return match ($configuration->provider) {
            BackupConfiguration::PROVIDER_SFTP,
            BackupConfiguration::PROVIDER_FTP => $this->curlUpload($configuration, $localPath, $objectPath, $scratchId),
            BackupConfiguration::PROVIDER_RCLONE => $this->rcloneTransfer($configuration, $localPath, $objectPath, $scratchId, upload: true),
            default => throw new InvalidArgumentException('Unsupported file-transport provider: '.$configuration->provider),
        };
    }

    /**
     * Pull `$objectPath` back down to `$localPath` on the server. Downloads for
     * these destinations are a two-hop trip — destination → server → browser —
     * because there is no presigned URL to hand the operator directly.
     *
     * @return array{command: string, files: array<string, string>}
     */
    public function downloadCommand(
        BackupConfiguration $configuration,
        string $objectPath,
        string $localPath,
        string $scratchId,
    ): array {
        return match ($configuration->provider) {
            BackupConfiguration::PROVIDER_SFTP,
            BackupConfiguration::PROVIDER_FTP => $this->curlDownload($configuration, $objectPath, $localPath, $scratchId),
            BackupConfiguration::PROVIDER_RCLONE => $this->rcloneTransfer($configuration, $localPath, $objectPath, $scratchId, upload: false),
            default => throw new InvalidArgumentException('Unsupported file-transport provider: '.$configuration->provider),
        };
    }

    /**
     * Delete a dump from the destination, for retention pruning.
     *
     * @return array{command: string, files: array<string, string>}
     */
    public function deleteCommand(BackupConfiguration $configuration, string $objectPath, string $scratchId): array
    {
        $config = $this->configFor($configuration);

        if ($configuration->provider === BackupConfiguration::PROVIDER_RCLONE) {
            $confPath = $this->scratchPath($scratchId, 'rclone.conf');
            $remote = trim((string) ($config['remote_name'] ?? ''));

            return [
                // deletefile, NOT delete: `rclone delete` removes the CONTENTS of
                // a directory and errors with "directory not found" when handed a
                // file path, so retention would silently never prune anything.
                'command' => sprintf(
                    'rclone --config %s deletefile %s',
                    escapeshellarg($confPath),
                    escapeshellarg($remote.':'.$objectPath),
                ),
                'files' => [$confPath => (string) ($config['config'] ?? '')],
            ];
        }

        // curl deletes over both protocols with a protocol-native command:
        // SFTP speaks `rm`, FTP speaks `DELE`.
        $configPath = $this->scratchPath($scratchId, 'curl.conf');
        $verb = $configuration->provider === BackupConfiguration::PROVIDER_SFTP ? 'rm' : 'DELE';

        return [
            'command' => sprintf(
                'curl --silent --show-error --fail-with-body --config %s --quote %s %s',
                escapeshellarg($configPath),
                escapeshellarg($verb.' '.$objectPath),
                escapeshellarg($this->baseUrl($configuration).'/'),
            ),
            'files' => [$configPath => $this->curlConfigFile($configuration, $scratchId)],
        ];
    }

    /**
     * A cheap "can dply reach this?" probe, used by the destination form's test
     * button. Lists the destination root; it writes nothing.
     *
     * @return array{command: string, files: array<string, string>}
     */
    public function probeCommand(BackupConfiguration $configuration, string $scratchId): array
    {
        $config = $this->configFor($configuration);

        if ($configuration->provider === BackupConfiguration::PROVIDER_RCLONE) {
            $confPath = $this->scratchPath($scratchId, 'rclone.conf');
            $remote = trim((string) ($config['remote_name'] ?? ''));
            $base = $this->normalizeBasePath((string) ($config['path'] ?? ''));

            return [
                'command' => sprintf(
                    'rclone --config %s --low-level-retries 1 lsd %s',
                    escapeshellarg($confPath),
                    escapeshellarg($remote.':'.$base),
                ),
                'files' => [$confPath => (string) ($config['config'] ?? '')],
            ];
        }

        $configPath = $this->scratchPath($scratchId, 'curl.conf');

        return [
            'command' => sprintf(
                'curl --silent --show-error --fail-with-body --list-only --config %s %s',
                escapeshellarg($configPath),
                escapeshellarg($this->baseUrl($configuration).$this->normalizeBasePath($this->basePathFor($configuration)).'/'),
            ),
            'files' => [$configPath => $this->curlConfigFile($configuration, $scratchId)],
        ];
    }

    /**
     * Files the caller must clean up after any of the commands above, whether
     * the transfer succeeded or failed. Secrets must not outlive the transfer.
     *
     * @param  array<string, string>  $files
     */
    public function cleanupCommand(array $files): string
    {
        if ($files === []) {
            return '';
        }

        return 'rm -f '.implode(' ', array_map(escapeshellarg(...), array_keys($files)));
    }

    /**
     * @return array{command: string, files: array<string, string>}
     */
    private function curlUpload(
        BackupConfiguration $configuration,
        string $localPath,
        string $objectPath,
        string $scratchId,
    ): array {
        $configPath = $this->scratchPath($scratchId, 'curl.conf');

        // --ftp-create-dirs makes curl build missing intermediate directories on
        // both protocols, so a dated key prefix works on a bare destination.
        return [
            'command' => sprintf(
                'curl --silent --show-error --fail-with-body --ftp-create-dirs --config %s --upload-file %s %s',
                escapeshellarg($configPath),
                escapeshellarg($localPath),
                escapeshellarg($this->baseUrl($configuration).'/'.ltrim($objectPath, '/')),
            ),
            'files' => [$configPath => $this->curlConfigFile($configuration, $scratchId)],
        ];
    }

    /**
     * @return array{command: string, files: array<string, string>}
     */
    private function curlDownload(
        BackupConfiguration $configuration,
        string $objectPath,
        string $localPath,
        string $scratchId,
    ): array {
        $configPath = $this->scratchPath($scratchId, 'curl.conf');

        return [
            'command' => sprintf(
                'curl --silent --show-error --fail-with-body --config %s --output %s %s',
                escapeshellarg($configPath),
                escapeshellarg($localPath),
                escapeshellarg($this->baseUrl($configuration).'/'.ltrim($objectPath, '/')),
            ),
            'files' => [$configPath => $this->curlConfigFile($configuration, $scratchId)],
        ];
    }

    /**
     * @return array{command: string, files: array<string, string>}
     */
    private function rcloneTransfer(
        BackupConfiguration $configuration,
        string $localPath,
        string $objectPath,
        string $scratchId,
        bool $upload,
    ): array {
        $config = $this->configFor($configuration);
        $remote = trim((string) ($config['remote_name'] ?? ''));
        if ($remote === '') {
            throw new InvalidArgumentException('Rclone destination is missing a remote name.');
        }

        $confPath = $this->scratchPath($scratchId, 'rclone.conf');
        $remoteRef = $remote.':'.$objectPath;

        // copyto (not copy) so the destination path is the file itself rather
        // than a directory to drop it into.
        return [
            'command' => sprintf(
                'rclone --config %s copyto %s %s',
                escapeshellarg($confPath),
                escapeshellarg($upload ? $localPath : $remoteRef),
                escapeshellarg($upload ? $remoteRef : $localPath),
            ),
            'files' => [$confPath => (string) ($config['config'] ?? '')],
        ];
    }

    /**
     * A curl `--config` file carrying the credentials. Keeping `user =` out of
     * argv is the whole point: a password on the command line is readable by
     * every process on the box.
     *
     * curl config syntax quotes values in double quotes with backslash escapes.
     */
    private function curlConfigFile(BackupConfiguration $configuration, string $scratchId): string
    {
        $config = $this->configFor($configuration);
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $lines = [];

        if ($configuration->provider === BackupConfiguration::PROVIDER_SFTP
            && trim((string) ($config['private_key'] ?? '')) !== '') {
            // Key auth: the key itself goes in its own mode-600 file (see
            // transportFiles()); an empty password still satisfies curl when the
            // key is unencrypted.
            $lines[] = 'key = '.$this->curlQuote($this->scratchPath($scratchId, 'key'));
            $lines[] = 'user = '.$this->curlQuote($username.':'.$password);
        } else {
            $lines[] = 'user = '.$this->curlQuote($username.':'.$password);
        }

        if ($configuration->provider === BackupConfiguration::PROVIDER_FTP) {
            // Opportunistic TLS: use it when the server offers it, rather than
            // failing outright against plain-FTP endpoints.
            $lines[] = 'ssl';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Every temp file a transfer needs on the server, including the private key
     * when SFTP uses key auth. Written mode 600, removed afterwards.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    public function withKeyFile(BackupConfiguration $configuration, array $files, string $scratchId): array
    {
        $config = $this->configFor($configuration);
        $key = (string) ($config['private_key'] ?? '');

        if ($configuration->provider === BackupConfiguration::PROVIDER_SFTP && trim($key) !== '') {
            // Trailing newline matters: OpenSSH rejects a key file without one.
            $files[$this->scratchPath($scratchId, 'key')] = rtrim($key, "\n")."\n";
        }

        return $files;
    }

    /** `sftp://host:port` or `ftp://host:port`, with no trailing slash. */
    private function baseUrl(BackupConfiguration $configuration): string
    {
        $config = $this->configFor($configuration);
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new InvalidArgumentException('Destination is missing a host.');
        }

        $scheme = $configuration->provider === BackupConfiguration::PROVIDER_SFTP ? 'sftp' : 'ftp';
        $default = $configuration->provider === BackupConfiguration::PROVIDER_SFTP ? 22 : 21;
        $port = (int) ($config['port'] ?? $default);
        if ($port < 1 || $port > 65535) {
            $port = $default;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    private function basePathFor(BackupConfiguration $configuration): string
    {
        return (string) ($this->configFor($configuration)['path'] ?? '');
    }

    /** Collapse `//`, strip a trailing slash, keep a leading one if given. */
    private function normalizeBasePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $leading = str_starts_with($path, '/') ? '/' : '';
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== ''));

        return $leading.implode('/', $parts);
    }

    /** @return array<string, mixed> */
    private function configFor(BackupConfiguration $configuration): array
    {
        return $configuration->config ?? [];
    }

    private function scratchPath(string $scratchId, string $suffix): string
    {
        // Scratch ids come from model ids, but a traversal here would write a
        // secret to an attacker-chosen path — so constrain it rather than trust it.
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $scratchId) ?? '';
        if ($safe === '') {
            $safe = 'dply';
        }

        return '/tmp/dply-xfer-'.$safe.'.'.$suffix;
    }

    private function curlQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
