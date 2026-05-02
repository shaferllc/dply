<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteRuntimePhpLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_php_process_section_title_matches_detected_framework(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $laravel = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => ['framework' => 'laravel', 'language' => 'php'],
                ],
            ],
        ]);
        $this->assertStringContainsString('Laravel', $laravel->runtimePhpProcessSectionTitle());

        $symfony = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => ['framework' => 'symfony', 'language' => 'php'],
                ],
            ],
        ]);
        $this->assertStringContainsString('Symfony', $symfony->runtimePhpProcessSectionTitle());
        $this->assertStringNotContainsString('Laravel', $symfony->runtimePhpProcessSectionTitle());

        $generic = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => ['framework' => 'php_generic', 'language' => 'php'],
                ],
            ],
        ]);
        $this->assertSame(__('PHP process'), $generic->runtimePhpProcessSectionTitle());

        $wordpress = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => ['framework' => 'wordpress', 'language' => 'php'],
                ],
            ],
        ]);
        $this->assertSame(__('PHP process'), $wordpress->runtimePhpProcessSectionTitle());
        $this->assertStringNotContainsString('WordPress', $wordpress->runtimePhpProcessSectionTitle());
    }

    public function test_runtime_scheduler_label_uses_laravel_only_when_laravel_detected(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $laravel = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => ['framework' => 'laravel', 'language' => 'php'],
                ],
            ],
        ]);
        $this->assertStringContainsString('Laravel', $laravel->runtimeSchedulerCheckboxLabel());

        $symfony = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'vm_runtime' => [
                    'detected' => ['framework' => 'symfony', 'language' => 'php'],
                ],
            ],
        ]);
        $this->assertStringNotContainsString('Laravel', $symfony->runtimeSchedulerCheckboxLabel());
        $this->assertNotNull($symfony->runtimeSchedulerCheckboxHelp());
    }
}
