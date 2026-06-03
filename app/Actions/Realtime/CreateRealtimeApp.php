<?php

declare(strict_types=1);

namespace App\Actions\Realtime;

use App\Jobs\ProvisionRealtimeAppJob;
use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Models\User;
use App\Services\Realtime\RealtimeBackendFactory;

/**
 * Creates a managed realtime app (status: provisioning) and dispatches the
 * provisioning job that publishes its credentials to the relay.
 */
class CreateRealtimeApp
{
    /**
     * @param  array{name: string}  $data
     */
    public function handle(User $user, Organization $organization, array $data): RealtimeApp
    {
        $credentials = RealtimeApp::generateCredentials();

        $app = $organization->realtimeApps()->create([
            'name' => trim($data['name']),
            'app_key' => $credentials['app_key'],
            'app_secret' => $credentials['app_secret'],
            'status' => RealtimeApp::STATUS_PROVISIONING,
            'backend' => RealtimeBackendFactory::make()->providerKey(),
            'host' => (string) config('realtime.host'),
            'max_connections' => (int) config('realtime.plan.max_connections'),
        ]);

        ProvisionRealtimeAppJob::dispatch($app->id);

        return $app;
    }
}
