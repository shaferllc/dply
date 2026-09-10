<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
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

        $emit = $this->beginConsoleAction();
        $username = $account->username;

        try {
            $emit->step('sftp_account', 'removing '.$username);
            $provisioner->destroy($account);

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
