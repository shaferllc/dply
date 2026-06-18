<?php

namespace Tests\Feature\Livewire\Serverless\CreateTest;

use App\Modules\Serverless\Livewire\Create as ServerlessCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Deploy\ServerlessRepositoryCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
});

function withCredential(User $user, Organization $org): void
{
    ProviderCredential::query()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
        'name' => 'DO main',
        'credentials' => ['token' => 'dop_v1_test'],
    ]);
}

test('shows a warning when no digitalocean credential exists', function () {
    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->assertSee('Connect a DigitalOcean credential');
});

test('load php demo prefills the form', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->call('loadPhpDemo')
        ->assertSet('repo', 'shaferllc/dply-demo-php-function')
        ->assertSet('branch', 'master')
        ->assertSet('runtime', 'php:8.3')
        ->assertSet('name', 'PHP demo');
});

test('load laravel demo prefills the form', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->call('loadLaravelDemo')
        ->assertSet('repo', 'shaferllc/dply-demo-laravel-function')
        ->assertSet('branch', 'master')
        ->assertSet('runtime', 'php:8.4')
        ->assertSet('name', 'Laravel demo');
});

test('php is an offered runtime', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->assertSee('PHP 8.3');
});

test('validation rejects empty name and repo', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('name', '')
        ->set('repo', '')
        ->call('create')
        ->assertHasErrors(['name', 'repo']);
});

test('happy path creates function and redirects', function () {
    Bus::fake();
    withCredential($this->user, $this->org);

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
    expect($site->git_repository_url)->toBe('acme/orders');

    $server = Server::find($site->server_id);
    expect($server->isServerlessHost())->toBeTrue();
});

test('runtime defaults to auto detect', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->assertSet('runtime', 'auto')
        ->assertSee('Auto-detect');
});

test('auto detect creates a function with an unset runtime', function () {
    Bus::fake();
    withCredential($this->user, $this->org);

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
    expect($site->meta['serverless']['runtime'])->toBe('');
});

test('validation rejects an unknown runtime', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('name', 'Bad Runtime')
        ->set('repo', 'acme/api')
        ->set('runtime', 'cobol:74')
        ->call('create')
        ->assertHasErrors(['runtime']);
});

test('detect from repository renders panel', function () {
    withCredential($this->user, $this->org);
    fakeServerlessCheckout(function (string $dir): void {
        file_put_contents($dir.'/main.php', "<?php\nfunction main(array \$args): array { return []; }\n");
    });

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('repo', 'acme/api')
        ->set('branch', 'main')
        ->call('detectFromRepository')
        ->assertSee('php:8.3')
        ->assertSee('raw');
});

test('detect from repository prefills runtime dropdown', function () {
    withCredential($this->user, $this->org);
    fakeServerlessCheckout(function (string $dir): void {
        file_put_contents($dir.'/main.php', "<?php\nfunction main(array \$args): array { return []; }\n");
    });

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('repo', 'acme/api')
        ->set('branch', 'main')
        ->call('detectFromRepository')
        ->assertSet('runtime', 'php:8.3');
});

test('detect from repository does not overwrite picked runtime', function () {
    withCredential($this->user, $this->org);
    fakeServerlessCheckout(function (string $dir): void {
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
});

test('detect from repository leaves dropdown on auto when nothing detected', function () {
    withCredential($this->user, $this->org);

    // An empty checkout — no framework markers, no raw main() entry file.
    fakeServerlessCheckout(fn (string $dir) => null);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('repo', 'acme/empty')
        ->set('branch', 'main')
        ->call('detectFromRepository')
        ->assertSet('runtime', 'auto')
        ->assertSee('No runtime detected');
});

/**
 * Bind a fake {@see ServerlessRepositoryCheckout} that resolves to a local
 * fixture directory instead of cloning over the network.
 */
function fakeServerlessCheckout(callable $populate): string
{
    $dir = sys_get_temp_dir().'/dply-sls-detect-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);
    $populate($dir);

    app()->instance(ServerlessRepositoryCheckout::class, new class($dir)
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
