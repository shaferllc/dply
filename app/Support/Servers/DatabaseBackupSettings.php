<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\BackupConfiguration;
use App\Models\Server;

/**
 * Per-server defaults for where database export jobs persist artifacts.
 * Stored in {@see Server::$meta} under `database_backup`.
 */
final class DatabaseBackupSettings
{
    public const KIND_REMOTE_SERVER = 'remote_server';

    public const KIND_DESTINATION = 'destination';

    public const KIND_CONTROL_PLANE = 'control_plane';

    public function __construct(
        public string $defaultKind = self::KIND_REMOTE_SERVER,
        public ?string $backupConfigurationId = null,
        public ?int $remoteMaxBytes = null,
    ) {}

    public static function fromServer(Server $server): self
    {
        $raw = $server->meta['database_backup'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        $kind = (string) ($raw['default_kind'] ?? self::KIND_REMOTE_SERVER);
        if (! in_array($kind, [self::KIND_REMOTE_SERVER, self::KIND_DESTINATION, self::KIND_CONTROL_PLANE], true)) {
            $kind = self::KIND_REMOTE_SERVER;
        }

        if ($kind === self::KIND_CONTROL_PLANE && ! config('server_database.allow_control_plane_storage', false)) {
            $kind = self::KIND_REMOTE_SERVER;
        }

        $configId = isset($raw['backup_configuration_id']) && $raw['backup_configuration_id'] !== ''
            ? (string) $raw['backup_configuration_id']
            : null;

        $maxBytes = isset($raw['remote_max_bytes']) ? (int) $raw['remote_max_bytes'] : null;

        return new self($kind, $configId, $maxBytes);
    }

    /**
     * Resolve storage for a single backup run.
     */
    public function resolveKind(?string $explicitBackupConfigurationId = null): string
    {
        if ($explicitBackupConfigurationId !== null && $explicitBackupConfigurationId !== '') {
            return self::KIND_DESTINATION;
        }

        if ($this->defaultKind === self::KIND_DESTINATION && $this->backupConfigurationId !== null) {
            return self::KIND_DESTINATION;
        }

        if ($this->defaultKind === self::KIND_CONTROL_PLANE && config('server_database.allow_control_plane_storage', false)) {
            return self::KIND_CONTROL_PLANE;
        }

        return self::KIND_REMOTE_SERVER;
    }

    public function resolveBackupConfigurationId(?string $explicitBackupConfigurationId = null): ?string
    {
        if ($explicitBackupConfigurationId !== null && $explicitBackupConfigurationId !== '') {
            return $explicitBackupConfigurationId;
        }

        if ($this->defaultKind === self::KIND_DESTINATION) {
            return $this->backupConfigurationId;
        }

        return null;
    }

    public function remoteMaxBytes(): int
    {
        if ($this->remoteMaxBytes !== null && $this->remoteMaxBytes > 0) {
            return $this->remoteMaxBytes;
        }

        return (int) config('server_database.remote_backup_max_bytes_per_server', 10 * 1024 * 1024 * 1024);
    }

    public function toMetaArray(): array
    {
        return array_filter([
            'default_kind' => $this->defaultKind,
            'backup_configuration_id' => $this->backupConfigurationId,
            'remote_max_bytes' => $this->remoteMaxBytes,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function backupConfigurationFor(Server $server): ?BackupConfiguration
    {
        $id = $this->backupConfigurationId;
        if ($id === null || $server->organization_id === null) {
            return null;
        }

        return BackupConfiguration::query()
            ->where('organization_id', $server->organization_id)
            ->whereKey($id)
            ->first();
    }
}
