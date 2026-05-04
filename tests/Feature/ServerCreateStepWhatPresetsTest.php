<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Servers\Create\StepWhat;
use App\Models\Organization;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServerCreateStepWhatPresetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_preset_for_laravel_pins_php_84_mysql_redis(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->call('applyPreset', 'laravel')
            ->assertSet('selectedPreset', 'laravel')
            ->assertSet('form.server_role', 'application')
            ->assertSet('form.webserver', 'nginx')
            ->assertSet('form.php_version', '8.4')
            ->assertSet('form.database', 'mysql84')
            ->assertSet('form.cache_service', 'redis')
            ->assertSet('form.install_profile', 'laravel_app');
    }

    public function test_apply_preset_for_rails_uses_postgres_and_application_role(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->call('applyPreset', 'rails')
            ->assertSet('form.server_role', 'application')
            ->assertSet('form.webserver', 'nginx')
            ->assertSet('form.database', 'postgres17')
            ->assertSet('form.cache_service', 'redis')
            ->assertSet('form.ruby_version', '3.3')
            // Rails has no PHP — applying the preset clears any prior pin
            // back to "none" so review screen reflects the actual stack.
            ->assertSet('form.php_version', 'none')
            ->assertSet('form.node_version', '')
            ->assertSet('form.python_version', '')
            ->assertSet('form.go_version', '');
    }

    public function test_apply_preset_for_polyglot_fills_every_language_runtime(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->call('applyPreset', 'polyglot')
            ->assertSet('form.ruby_version', '3.3')
            ->assertSet('form.node_version', '22')
            ->assertSet('form.python_version', '3.12')
            ->assertSet('form.go_version', '1.22')
            ->assertSet('form.php_version', '8.4');
    }

    public function test_switching_from_rails_to_laravel_clears_ruby_pin(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->call('applyPreset', 'rails')
            ->assertSet('form.ruby_version', '3.3')
            ->call('applyPreset', 'laravel')
            ->assertSet('form.ruby_version', '')
            ->assertSet('form.php_version', '8.4');
    }

    public function test_apply_preset_for_polyglot_keeps_php_pinned(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->call('applyPreset', 'polyglot')
            ->assertSet('form.php_version', '8.4')
            ->assertSet('form.database', 'postgres17')
            ->assertSet('form.cache_service', 'redis');
    }

    public function test_apply_preset_for_static_clears_selection_to_static_role(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->call('applyPreset', 'static')
            ->assertSet('form.server_role', 'static')
            ->assertSet('form.install_profile', 'static_app_host');
    }

    public function test_apply_preset_for_custom_marks_selection_without_changing_form(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->set('form.server_role', 'application')
            ->set('form.php_version', '8.3')
            ->call('applyPreset', 'custom')
            ->assertSet('selectedPreset', 'custom')
            ->assertSet('form.server_role', 'application')
            ->assertSet('form.php_version', '8.3');
    }

    public function test_apply_preset_for_unknown_id_is_a_no_op(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->set('form.server_role', 'application')
            ->call('applyPreset', 'made-up')
            ->assertSet('selectedPreset', '')
            ->assertSet('form.server_role', 'application');
    }

    public function test_step_what_view_renders_featured_preset_tiles(): void
    {
        $user = $this->seedUserWithDraft();

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->assertSee('Polyglot host')
            ->assertSee('Laravel app')
            ->assertSee('Rails app')
            ->assertSee('Next.js / Node API')
            ->assertSee('Django / FastAPI');
    }

    private function seedUserWithDraft(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        ServerCreateDraft::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'step' => 3,
            'payload' => [
                'mode' => 'provider',
                'type' => 'digitalocean',
                'name' => 'test',
                'install_profile' => 'laravel_app',
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        return $user;
    }
}
