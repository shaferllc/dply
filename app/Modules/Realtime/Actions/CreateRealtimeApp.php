<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Actions;

use App\Modules\Realtime\Jobs\ProvisionRealtimeAppJob;
use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Models\User;
use App\Modules\Realtime\Services\RealtimeBackendFactory;

/**
 * Creates a managed realtime app (status: provisioning) and dispatches the
 * provisioning job that publishes its credentials to the relay.
 */
class CreateRealtimeApp
{
    /**
     * @param  array{name: string, tier?: string}  $data
     */
    public function handle(User $user, Organization $organization, array $data): RealtimeApp
    {
        $credentials = RealtimeApp::generateCredentials();

        $tier = (string) ($data['tier'] ?? config('realtime.default_tier', 'starter'));
        if (! array_key_exists($tier, (array) config('realtime.tiers', []))) {
            $tier = (string) config('realtime.default_tier', 'starter');
        }
        $maxConnections = (int) config("realtime.tiers.{$tier}.max_connections", config('realtime.plan.max_connections'));

        $app = $organization->realtimeApps()->create([
            'name' => trim($data['name']),
            'app_key' => $credentials['app_key'],
            'app_secret' => $credentials['app_secret'],
            'status' => RealtimeApp::STATUS_PROVISIONING,
            'backend' => RealtimeBackendFactory::make()->providerKey(),
            'tier' => $tier,
            'host' => (string) config('realtime.host'),
            'max_connections' => $maxConnections,
        ]);

        ProvisionRealtimeAppJob::dispatch($app->id);

        return $app;
    }
}
