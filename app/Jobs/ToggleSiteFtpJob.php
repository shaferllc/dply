<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
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
 * Site-wide FTP kill switch.
 *
 * Turning FTP off leaves the accounts and their grants in place so the switch
 * is reversible — it only removes group membership and locks the passwords.
 * Deleting the accounts would be a one-way door wearing a toggle's clothing.
 */
class ToggleSiteFtpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(
        public string $siteId,
        public bool $enabled,
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
            $usernames = SftpAccount::query()
                ->where('site_id', $site->id)
                ->pluck('username')
                ->all();

            $provisioner->setSiteAccountsEnabled(
                $site->server,
                $usernames,
                $this->enabled,
                fn (string $message) => $emit->step('sftp_account', $message),
            );

            // Persisted only after the box agrees, so the UI can never claim
            // FTP is off while the accounts still authenticate.
            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['ftp_disabled'] = ! $this->enabled;
            $site->update(['meta' => $meta]);

            $emit->success($this->enabled ? 'ftp enabled for this site' : 'ftp disabled for this site', 'sftp_account');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'sftp_account');
            $this->failConsoleAction($e->getMessage());

            Log::warning('ToggleSiteFtpJob failed', [
                'site_id' => $this->siteId,
                'enabled' => $this->enabled,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
