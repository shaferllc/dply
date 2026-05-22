<?php


namespace Tests\Unit\Services\DeploymentContractBuilderTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use App\Services\Deploy\DeploymentContractBuilder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it builds a typed contract with environment and bindings', function () {
    $workspace = Workspace::factory()->create();
    $workspace->variables()->create([
        'env_key' => 'APP_NAME',
        'env_value' => 'workspace-name',
        'is_secret' => false,
    ]);

    $server = Server::factory()->create([
        'organization_id' => $workspace->organization_id,
        'user_id' => $workspace->user_id,
        'workspace_id' => $workspace->id,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $workspace->organization_id,
        'user_id' => $workspace->user_id,
        'workspace_id' => $workspace->id,
        'deployment_environment' => 'production',
        'env_file_content' => "APP_KEY=base64:test-key\nAPP_URL=https://example.test",
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                ],
            ],
        ],
    ]);

    $contract = app(DeploymentContractBuilder::class)->build($site->fresh());

    expect($contract->config['environment_name'])->toBe('production');
    expect($contract->environmentMap()['APP_KEY'])->toBe('base64:test-key');
    expect($contract->environmentMap()['APP_NAME'])->toBe('workspace-name');
    expect($contract->environmentMap()['SESSION_DRIVER'])->toBe('file');
    expect($contract->revision())->not->toBeEmpty();
    expect(collect($contract->resourceBindings)->contains(fn ($binding) => $binding->type === 'publication'))->toBeTrue();
});