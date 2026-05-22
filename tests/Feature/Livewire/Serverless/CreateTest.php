<?php

namespace Tests\Feature\Livewire\Serverless;

use App\Livewire\Serverless\Create as ServerlessCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Deploy\ServerlessRepositoryCheckout;
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

    public function test_detect_from_repository_renders_panel(): void
    {
        $this->withCredential();
        $this->fakeServerlessCheckout(function (string $dir): void {
            file_put_contents($dir.'/main.php', "<?php\nfunction main(array \$args): array { return []; }\n");
        });

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->call('detectFromRepository')
            ->assertSee('php:8.3')
            ->assertSee('raw');
    }

    public function test_detect_from_repository_prefills_runtime_dropdown(): void
    {
        $this->withCredential();
        $this->fakeServerlessCheckout(function (string $dir): void {
            file_put_contents($dir.'/main.php', "<?php\nfunction main(array \$args): array { return []; }\n");
        });

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->call('detectFromRepository')
            ->assertSet('runtime', 'php:8.3');
    }

    public function test_detect_from_repository_does_not_overwrite_picked_runtime(): void
    {
        $this->withCredential();
        $this->fakeServerlessCheckout(function (string $dir): void {
            file_put_contents($dir.'/main.php', "<?php\nfunction main(array \$args): array { return []; }\n");
        });

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            // Picking a runtime first marks it touched — detect must not stomp it.
            ->set('runtime', 'go:1.22')
            ->set('repo', 'acme/api')
            ->set('branch', 'main')
            ->call('detectFromRepository')
            ->assertSet('runtime', 'go:1.22');
    }

    public function test_detect_from_repository_leaves_dropdown_on_auto_when_nothing_detected(): void
    {
        $this->withCredential();
        // An empty checkout — no framework markers, no raw main() entry file.
        $this->fakeServerlessCheckout(fn (string $dir) => null);

        Livewire::actingAs($this->user)
            ->test(ServerlessCreate::class)
            ->set('repo', 'acme/empty')
            ->set('branch', 'main')
            ->call('detectFromRepository')
            ->assertSet('runtime', 'auto')
            ->assertSee('No runtime detected');
    }

    /**
     * Bind a fake {@see ServerlessRepositoryCheckout} that resolves to a local
     * fixture directory instead of cloning over the network.
     */
    private function fakeServerlessCheckout(callable $populate): string
    {
        $dir = sys_get_temp_dir().'/dply-sls-detect-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $populate($dir);

        $this->app->instance(ServerlessRepositoryCheckout::class, new class($dir)
        {
            public function __construct(private string $dir) {}

            /**
             * @return array<string, string>
             */
            public function checkout(): array
            {
                return [
                    'workspace_path' => $this->dir,
                    'repository_path' => $this->dir,
                    'working_directory' => $this->dir,
                    'output' => '',
                    'branch' => 'main',
                ];
            }

            public function cleanup(string $workspacePath): void {}
        });

        return $dir;
    }
}
