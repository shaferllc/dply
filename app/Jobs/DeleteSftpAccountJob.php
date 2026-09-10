<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\SftpAccount;
use App\Models\Site;
use App\Services\Servers\SftpAccountProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Removes the Linux account, its ACL grants and its home, then the DB row.
 *
 * The row is deleted only after the remote teardown succeeds. A failed teardown
 * leaves the row in `error` so the account stays visible and retryable — the
 * alternative (delete the row, leave the account) is an unlisted login on a
 * customer's box.
 */
class DeleteSftpAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(
        public string $accountId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function uniqueId(): string
    {
        return 'console-action:sftp_account:'.$this->accountId;
    }

    protected function consoleSubject(): Model
    {
        $account = SftpAccount::findOrFail($this->accountId);

        return $account->site_id !== null
            ? Site::findOrFail($account->site_id)
            : Server::findOrFail($account->server_id);
    }

    protected function consoleKind(): string
    {
        return 'sftp_account';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SftpAccountProvisioner $provisioner): void
    {
        $account = SftpAccount::with(['server', 'site'])->find($this->accountId);
        if (! $account) {
            return;
        }

        // Pin the worker to the row the UI seeded at dispatch so the banner
        // the operator is already watching fills in, rather than a second one.
        $this->bindConsoleRunId($this->seededConsoleRunId);

        $emit = $this->beginConsoleAction();
        $username = $account->username;

        try {
            $provisioner->destroy(
                $account,
                fn (string $message) => $emit->step('sftp_account', $message),
            );

            // Authorized keys are stored per Linux user, not per FTP account, so
            // they outlive the account unless removed here. Left behind they
            // would be re-synced onto a user that no longer exists (created
            // accounts) or silently keep granting access (adopted ones).
            $removedKeys = ServerAuthorizedKey::query()
                ->where('server_id', $account->server_id)
                ->where('target_linux_user', $username)
                ->delete();

            if ($removedKeys > 0) {
                $emit->step('sftp_account', 'removed '.$removedKeys.' ssh key(s) for '.$username);
                SyncAuthorizedKeysJob::dispatch(
                    (string) $account->server_id,
                    (string) Str::ulid(),
                    $this->userId,
                );
            }

            $account->delete();

            $emit->success('ftp account '.$username.' removed', 'sftp_account');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $account->update([
                'status' => SftpAccount::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ]);

            $emit->error($e->getMessage(), 'sftp_account');
            $this->failConsoleAction($e->getMessage());

            Log::warning('DeleteSftpAccountJob failed', [
                'sftp_account_id' => $account->id,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
