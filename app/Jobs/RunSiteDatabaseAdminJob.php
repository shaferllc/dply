<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseExtraUser;
use App\Models\Site;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Queued database-admin operations for the site Database tab — extra-user
 * create/remove and remote drop. SSH never runs inline in the request (the
 * always-queue rule, same as {@see CreateSiteDatabaseJob}); progress streams into
 * a {@see ConsoleAction} on the site so the tab banner shows live output.
 *
 * The owning {@see ServerDatabase} (and, for add_user, the encrypted
 * {@see ServerDatabaseExtraUser} row) is created by the dispatching Livewire
 * component before this runs, so a credential-share link is available
 * immediately; this job owns the slow remote work.
 */
class RunSiteDatabaseAdminJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public const OP_ADD_USER = 'add_user';

    public const OP_DROP_USER = 'drop_user';

    public const OP_DROP_DATABASE = 'drop_database';

    public const OP_ROTATE_PASSWORD = 'rotate_password';

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $operation,
        public string $serverDatabaseId,
        public string $siteId,
        public ?string $extraUserId = null,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'site_db_admin';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $audit,
    ): void {
        $db = ServerDatabase::query()->with('server')->find($this->serverDatabaseId);
        $site = Site::query()->find($this->siteId);
        if (! $db instanceof ServerDatabase || ! $site instanceof Site || $db->server === null) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            match ($this->operation) {
                self::OP_ADD_USER => $this->addUser($emit, $provisioner, $audit, $db),
                self::OP_DROP_USER => $this->dropUser($emit, $provisioner, $audit, $db),
                self::OP_DROP_DATABASE => $this->dropDatabase($emit, $provisioner, $audit, $db, $site),
                self::OP_ROTATE_PASSWORD => $this->rotatePassword($emit, $provisioner, $audit, $db),
                default => throw new \InvalidArgumentException('Unknown database admin operation.'),
            };

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit($e->getMessage(), ConsoleAction::LEVEL_ERROR, 'db');
            $this->failConsoleAction($e->getMessage());
        }
    }

    private function addUser($emit, ServerDatabaseProvisioner $provisioner, ServerDatabaseAuditLogger $audit, ServerDatabase $db): void
    {
        $extra = ServerDatabaseExtraUser::query()->find($this->extraUserId);
        if (! $extra instanceof ServerDatabaseExtraUser) {
            throw new \RuntimeException('That user record no longer exists.');
        }

        $emit->step('db', sprintf('CREATE USER %s ON %s', $extra->username, $db->name));
        try {
            $out = $provisioner->createExtraDatabaseUser($db, $extra);
        } catch (\Throwable $e) {
            // Roll back the local row so the tab doesn't list a user that isn't
            // on the host (mirrors the server-level manager).
            $extra->delete();
            throw $e;
        }

        $this->echoLines($emit, $out);
        $audit->record($db->server, ServerDatabaseAuditEvent::EVENT_EXTRA_USER_CREATED, [
            'server_database_id' => $db->id,
            'username' => $extra->username,
            'source' => 'site_workspace',
        ]);
        $emit->success(sprintf('User %s created on %s.', $extra->username, $db->name), 'db');
    }

    private function dropUser($emit, ServerDatabaseProvisioner $provisioner, ServerDatabaseAuditLogger $audit, ServerDatabase $db): void
    {
        $extra = ServerDatabaseExtraUser::query()->find($this->extraUserId);
        if (! $extra instanceof ServerDatabaseExtraUser) {
            return;
        }

        $emit->step('db', sprintf('DROP USER %s ON %s', $extra->username, $db->name));
        $out = $provisioner->dropExtraDatabaseUser($db, $extra);
        $this->echoLines($emit, $out);

        $username = $extra->username;
        $extra->delete();

        $audit->record($db->server, ServerDatabaseAuditEvent::EVENT_EXTRA_USER_REMOVED, [
            'server_database_id' => $db->id,
            'username' => $username,
            'source' => 'site_workspace',
        ]);
        $emit->success(sprintf('User %s removed from %s.', $username, $db->name), 'db');
    }

    private function dropDatabase($emit, ServerDatabaseProvisioner $provisioner, ServerDatabaseAuditLogger $audit, ServerDatabase $db, Site $site): void
    {
        $emit->step('db', sprintf('DROP %s DATABASE %s', strtoupper($db->engine), $db->name));
        $out = $provisioner->dropFromServer($db);
        $this->echoLines($emit, $out);

        $audit->record($db->server, ServerDatabaseAuditEvent::EVENT_DATABASE_DROPPED_REMOTE, [
            'server_database_id' => $db->id,
            'site_id' => $site->id,
            'name' => $db->name,
            'source' => 'site_workspace',
        ]);

        $name = $db->name;
        $db->delete();
        $emit->success(sprintf('Database %s dropped on the server and removed from Dply.', $name), 'db');
    }

    private function rotatePassword($emit, ServerDatabaseProvisioner $provisioner, ServerDatabaseAuditLogger $audit, ServerDatabase $db): void
    {
        // The new password was already written to the encrypted row by the
        // dispatching component (so a credential-share link works immediately);
        // here we apply it on the host.
        $emit->step('db', sprintf('ALTER USER %s ON %s', $db->username, $db->name));
        $out = $provisioner->setUserPassword($db, (string) $db->username, (string) $db->host, (string) $db->password);
        $this->echoLines($emit, $out);

        $audit->record($db->server, ServerDatabaseAuditEvent::EVENT_DATABASE_UPDATED, [
            'server_database_id' => $db->id,
            'name' => $db->name,
            'change' => 'password_rotated',
            'source' => 'site_workspace',
        ]);
        $emit->success(sprintf('Password rotated for %s on %s.', $db->username, $db->name), 'db');
    }

    private function echoLines($emit, ?string $out): void
    {
        foreach (preg_split("/\r?\n/", (string) $out) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, ConsoleAction::LEVEL_INFO, 'db');
            }
        }
    }
}
