<?php

namespace Tests\Feature\Livewire\Serverless;

use App\Livewire\Serverless\Create as ServerlessCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class CreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    }

    private function withCredential(): void
    {
        ProviderCredential::query()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'provider' => 'digitalocean',
            'name' => 'DO main',
            'credentials' => ['token' => 'dop_v1_test'],
        ]);
    }

    public function test_shows_a_warning_when_no_digitalocean_credential_exists(): void
    {
        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->assertSee('Connect a DigitalOcean credential');
    }

    public function test_load_php_demo_prefills_the_form(): void
    {
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->call('loadPhpDemo')
            ->assertSet('repo', 'shaferllc/dply-demo-php-function')
            ->assertSet('branch', 'master')
            ->assertSet('runtime', 'php:8.3')
            ->assertSet('name', 'PHP demo');
    }

    public function test_load_laravel_demo_prefills_the_form(): void
    {
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->call('loadLaravelDemo')
            ->assertSet('repo', 'shaferllc/dply-demo-laravel-function')
            ->assertSet('branch', 'master')
            ->assertSet('runtime', 'php:8.4')
            ->assertSet('name', 'Laravel demo');
    }

    public function test_php_is_an_offered_runtime(): void
    {
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->assertSee('PHP 8.3');
    }

    public function test_validation_rejects_empty_name_and_repo(): void
    {
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->set('name', '')
            ->set('repo', '')
            ->call('create')
            ->assertHasErrors(['name', 'repo']);
    }

    public function test_happy_path_creates_function_and_redirects(): void
    {
        Bus::fake();
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->set('name', 'Orders API')
            ->set('repo', 'acme/orders')
            ->set('branch', 'main')
            ->set('runtime', 'nodejs:20')
            ->set('region', 'nyc1')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $site = Site::query()->where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame('acme/orders', $site->git_repository_url);

        $server = Server::find($site->server_id);
        $this->assertTrue($server->isServerlessHost());
    }

    public function test_runtime_defaults_to_auto_detect(): void
    {
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->assertSet('runtime', 'auto')
            ->assertSee('Auto-detect');
    }

    public function test_auto_detect_creates_a_function_with_an_unset_runtime(): void
    {
        Bus::fake();
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->set('name', 'Detected Fn')
            ->set('repo', 'acme/detected')
            ->set('branch', 'main')
            ->set('runtime', 'auto')
            ->set('region', 'nyc1')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $site = Site::query()->where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame('', $site->meta['serverless']['runtime']);
    }

    public function test_validation_rejects_an_unknown_runtime(): void
    {
        $this->withCredential();

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->set('name', 'Bad Runtime')
            ->set('repo', 'acme/api')
            ->set('runtime', 'cobol:74')
            ->call('create')
            ->assertHasErrors(['runtime']);
    }
}
