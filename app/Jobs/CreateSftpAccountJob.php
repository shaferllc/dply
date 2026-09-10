<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\SftpAccount;
use App\Models\Site;
use App\Services\Servers\SftpAccountProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Creates the Linux account behind an `sftp_accounts` row.
 *
 * ShouldBeEncrypted because the payload carries the plaintext password. dply
 * generates it in the web process (so the operator can be shown it once) and
 * hands it to the worker to apply; it is never written to the database, and
 * encryption keeps it out of plaintext on the queue backend in the meantime.
 *
 * The password must not reach $emit->step() or Log — the console-action output
 * is rendered in the UI and persisted.
 */
class CreateSftpAccountJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(
        public string $accountId,
        public string $password,
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

        try {
            $emit->step('sftp_account', 'creating '.$account->username);
            $provisioner->provision($account, $this->password);

            $account->update([
                'status' => SftpAccount::STATUS_ACTIVE,
                'last_error' => null,
                'provisioned_at' => now(),
            ]);

            $emit->success('ftp account '.$account->username.' ready', 'sftp_account');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $account->update([
                'status' => SftpAccount::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ]);

            $emit->error($e->getMessage(), 'sftp_account');
            $this->failConsoleAction($e->getMessage());

            Log::warning('CreateSftpAccountJob failed', [
                'sftp_account_id' => $account->id,
                'username' => $account->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
