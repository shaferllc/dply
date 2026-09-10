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
 * Applies a freshly generated password. This is the whole recovery story for a
 * lost password — dply never stored the old one, so there is nothing to look up.
 *
 * ShouldBeEncrypted for the same reason as {@see CreateSftpAccountJob}.
 */
class ResetSftpAccountPasswordJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

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
            $emit->step('sftp_account', 'resetting password for '.$account->username);
            $provisioner->setPassword($account, $this->password);

            $account->update(['status' => SftpAccount::STATUS_ACTIVE, 'last_error' => null]);

            $emit->success('password reset for '.$account->username, 'sftp_account');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $account->update(['last_error' => $e->getMessage()]);

            $emit->error($e->getMessage(), 'sftp_account');
            $this->failConsoleAction($e->getMessage());

            Log::warning('ResetSftpAccountPasswordJob failed', [
                'sftp_account_id' => $account->id,
                'username' => $account->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
