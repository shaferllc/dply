<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\Blueprint;

use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerBlueprint;
use App\Models\ServerFirewallRule;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Services\Servers\Blueprint\ServerBlueprintApplier;
use App\Services\Servers\Blueprint\ServerBlueprintCapture;
use App\Services\Servers\Blueprint\ServerBlueprintSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);

test('capture builds snapshot from installed stack and baselines', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'server_role' => 'application',
            'install_profile' => 'laravel_app',
            'runtime_defaults' => ['node' => '22'],
            'installed_stack' => [
                'database' => 'mysql84',
                'database_version' => '8.4',
                'php_version' => '8.4',
                'webserver' => 'nginx',
                'cache_service' => 'redis',
                'low_mem_mode' => false,
            ],
        ],
    ]);

    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'SSH',
        'port' => '22',
        'protocol' => 'tcp',
        'source' => 'anywhere',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 0,
    ]);

    SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'slug' => 'horizon',
        'program_type' => 'worker',
        'command' => 'php artisan horizon',
        'directory' => '/var/www',
        'user' => 'deploy',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $snapshot = app(ServerBlueprintCapture::class)->fromServer($server->fresh(['firewallRules', 'supervisorPrograms']));

    expect($snapshot['stack']['database'])->toBe('mysql84')
        ->and($snapshot['stack']['php_version'])->toBe('8.4')
        ->and($snapshot['runtime_defaults'])->toBe(['node' => '22'])
        ->and($snapshot['firewall_rules'])->toHaveCount(1)
        ->and($snapshot['supervisor_programs'])->toHaveCount(1);
});

test('applier maps blueprint snapshot onto create form fields', function (): void {
    $blueprint = ServerBlueprint::factory()->create([
        'snapshot' => [
            'version' => 1,
            'stack' => [
                'database' => 'postgres17',
                'php_version' => '8.3',
                'webserver' => 'nginx',
                'cache_service' => 'redis',
            ],
            'server_role' => 'database',
            'install_profile' => 'plain',
            'runtime_defaults' => ['python' => '3.12'],
            'firewall_rules' => [],
            'supervisor_programs' => [],
        ],
    ]);

    $form = new ServerCreateForm(new class extends Component {}, 'form');
    app(ServerBlueprintApplier::class)->applyToForm($form, $blueprint);

    expect($form->database)->toBe('postgres17')
        ->and($form->php_version)->toBe('8.3')
        ->and($form->server_role)->toBe('database')
        ->and($form->python_version)->toBe('3.12')
        ->and($form->server_blueprint_id)->toBe($blueprint->id);
});

test('summary renders stack tagline', function (): void {
    $tagline = app(ServerBlueprintSummary::class)->tagline([
        'stack' => [
            'webserver' => 'nginx',
            'php_version' => '8.4',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    expect($tagline)->toContain('Nginx')
        ->and($tagline)->toContain('PHP 8.4')
        ->and($tagline)->toContain('MySQL 8.4');
});
