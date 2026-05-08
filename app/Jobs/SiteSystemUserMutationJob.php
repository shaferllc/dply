<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesSiteApplyState;
use App\Models\Site;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SiteSystemUserMutationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesSiteApplyState;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $siteId,
        public string $operation,
        public string $username,
        public bool $grantSudo = false,
    ) {}

    public function uniqueId(): string
    {
        return 'site-system-user:'.$this->siteId;
    }

    protected function applyKind(): string
    {
        return 'system_user';
    }

    public function handle(ServerSystemUserService $service): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $runId = $this->beginApplyRun($site);

        try {
            if ($this->operation === 'create') {
                $service->createUserAndAssignSite($site, $this->username, $this->grantSudo);
            } elseif ($this->operation === 'assign') {
                $service->assignExistingUserToSite($site->fresh(), $this->username);
            } else {
                throw new \InvalidArgumentException('Invalid system user operation.');
            }

            $this->completeApplyRun($site);
        } catch (\Throwable $e) {
            $this->cacheApplyOutput($runId, $e->getMessage());
            $this->failApplyRun(
                $site,
                $e->getMessage(),
                extraMeta: ['system_user_operation' => [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'at' => now()->toIso8601String(),
                ]],
            );

            Log::warning('SiteSystemUserMutationJob failed', [
                'site_id' => $site->id,
                'operation' => $this->operation,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
