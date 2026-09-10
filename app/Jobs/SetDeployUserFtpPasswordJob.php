<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
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
 * Sets or clears a password login for the server's deploy user.
 *
 * Joins the password-only group, never the file-transfer group — the deploy
 * user must keep its shell or every deploy on this server breaks.
 *
 * ShouldBeEncrypted: the payload carries a plaintext password, which dply shows
 * once and never stores.
 */
class SetDeployUserFtpPasswordJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public string $siteId,
        public string $username,
        public ?string $password = null,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function uniqueId(): string
    {
        return 'console-action:sftp_account:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
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
        $site = Site::with('server')->find($this->siteId);
        if (! $site || $site->server === null) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            if ($this->password === null) {
                $emit->step('sftp_account', 'removing password login for '.$this->username);
                $provisioner->clearDeployUserPassword($site->server, $this->username);
                $message = 'password login removed for '.$this->username;
            } else {
                $emit->step('sftp_account', 'enabling password login for '.$this->username);
                $provisioner->setDeployUserPassword($site->server, $this->username, $this->password);
                $message = 'password login enabled for '.$this->username;
            }

            // Server-scoped, so it lives on the server rather than this site:
            // the deploy user is shared by every site on the box.
            $server = $site->server;
            $meta = is_array($server->meta) ? $server->meta : [];
            $meta['deploy_user_ftp_password'] = $this->password !== null;
            $server->update(['meta' => $meta]);

            $emit->success($message, 'sftp_account');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'sftp_account');
            $this->failConsoleAction($e->getMessage());

            Log::warning('SetDeployUserFtpPasswordJob failed', [
                'site_id' => $this->siteId,
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
