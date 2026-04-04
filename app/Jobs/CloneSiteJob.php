<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Clone\SiteCloneDestinationValidator;
use App\Services\Sites\Clone\SiteCloneStrategyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CloneSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public string $sourceSiteId,
        public string $destinationServerId,
        public string $primaryHostname,
        public string $siteName,
        public string $userId,
    ) {}

    public function handle(SiteCloneStrategyResolver $resolver): void
    {
        $source = Site::query()->with('server')->find($this->sourceSiteId);
        $dest = Server::query()->find($this->destinationServerId);
        $user = User::query()->find($this->userId);

        if (! $source || ! $dest || ! $user) {
            return;
        }

        try {
            SiteCloneDestinationValidator::validateOrFail($user, $source, $dest, $this->primaryHostname);
        } catch (\Throwable $e) {
            Log::warning('CloneSiteJob validation failed', [
                'source_site_id' => $this->sourceSiteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $strategy = $resolver->for($source->fresh());

        try {
            $strategy->execute($source->fresh(), $dest->fresh(), $this->primaryHostname, $this->siteName);
        } catch (\Throwable $e) {
            Log::warning('CloneSiteJob failed', [
                'source_site_id' => $source->id,
                'destination_server_id' => $dest->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
