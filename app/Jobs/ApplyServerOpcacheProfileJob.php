<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerPhpOpcacheProfile;
use App\Services\Servers\ServerOpcacheConfigEditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Render a {@see ServerPhpOpcacheProfile} into an opcache.ini body and ship
 * it to the host, then reload php-fpm for that version. Idempotent — safe
 * to re-dispatch after a transient SSH failure.
 */
class ApplyServerOpcacheProfileJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(
        public string $profileId,
    ) {}

    public function handle(ServerOpcacheConfigEditor $editor): void
    {
        /** @var ServerPhpOpcacheProfile|null $profile */
        $profile = ServerPhpOpcacheProfile::query()->with('server')->find($this->profileId);
        if ($profile === null || $profile->server === null) {
            return;
        }

        $profile->update([
            'status' => ServerPhpOpcacheProfile::STATUS_INSTALLING,
            'last_error' => null,
        ]);

        try {
            $editor->apply($profile->server, $profile);

            $profile->update([
                'status' => ServerPhpOpcacheProfile::STATUS_ACTIVE,
                'last_applied_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $profile->update([
                'status' => ServerPhpOpcacheProfile::STATUS_FAILED,
                'last_error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }
}
