<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SiteSystemUserMutationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $siteId,
        public string $operation,
        public string $username,
        public bool $grantSudo = false,
    ) {}

    public function handle(ServerSystemUserService $service): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        try {
            if ($this->operation === 'create') {
                $service->createUserAndAssignSite($site, $this->username, $this->grantSudo);
            } elseif ($this->operation === 'assign') {
                $service->assignExistingUserToSite($site->fresh(), $this->username);
            } else {
                throw new \InvalidArgumentException('Invalid system user operation.');
            }
        } catch (\Throwable $e) {
            $this->recordFailure($site, $e->getMessage());

            Log::warning('SiteSystemUserMutationJob failed', [
                'site_id' => $site->id,
                'operation' => $this->operation,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function recordFailure(Site $site, string $message): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['system_user_operation'] = [
            'status' => 'error',
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $site->update(['meta' => $meta]);
    }
}
