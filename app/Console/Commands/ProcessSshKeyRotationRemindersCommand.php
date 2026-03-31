<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Notifications\SshKeyRotationDueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessSshKeyRotationRemindersCommand extends Command
{
    protected $signature = 'dply:ssh-key-rotation-reminders';

    protected $description = 'Notify server owners when stored SSH keys pass their review-after date';

    public function handle(): int
    {
        $today = now()->toDateString();

        ServerAuthorizedKey::query()
            ->whereNotNull('review_after')
            ->whereDate('review_after', '<=', $today)
            ->whereHas('server', fn ($q) => $q->where('status', Server::STATUS_READY))
            ->with(['server.user'])
            ->chunkById(100, function ($keys) use ($today): void {
                foreach ($keys as $key) {
                    $user = $key->server?->user;
                    if (! $user) {
                        continue;
                    }

                    $cacheKey = 'ssh_key_rotation_reminder:'.$key->id.':'.$today;
                    if (Cache::has($cacheKey)) {
                        continue;
                    }

                    $user->notify(new SshKeyRotationDueNotification($key));
                    Cache::put($cacheKey, true, now()->addHours(36));
                }
            });

        $this->info('SSH key rotation reminders processed.');

        return self::SUCCESS;
    }
}
