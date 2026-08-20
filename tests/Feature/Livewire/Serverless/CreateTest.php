<?php

namespace Tests\Feature\Livewire\Serverless\CreateTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\Deploy\Services\ServerlessRepositoryCheckout;
use App\Modules\Serverless\Livewire\Create as ServerlessCreate;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);

    // Auto-detect now runs on repo/branch changes — keep create tests off the network.
    fakeServerlessCheckout(fn (string $dir) => null);
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
        ->assertSee('Connect a DigitalOcean credential')
        ->assertSee('Add credentials');
});

test('happy path persists connected git account on create', function () {
    Bus::fake();
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('name', 'Private App')
        ->set('repo_source', 'provider')
        ->set('source_control_account_id', '01HXTESTACCOUNTID000000000')
        ->set('repository_selection', 'https://github.com/acme/private.git')
        ->set('git_repository_url', 'https://github.com/acme/private.git')
        ->set('git_branch', 'main')
        ->set('git_ref_kind', 'branch')
        ->set('runtime', 'php:8.4')
        ->set('region', 'nyc1')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $site = Site::query()->where('organization_id', $this->org->id)->firstOrFail();
    expect($site->meta['serverless']['source_control_account_id'])->toBe('01HXTESTACCOUNTID000000000');
    expect($site->meta['serverless']['repo_source'])->toBe('provider');
});

test('load php demo prefills the form', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->call('loadPhpDemo')
        ->assertSet('git_repository_url', 'shaferllc/dply-demo-php-function')
        ->assertSet('git_branch', 'master')
        ->assertSet('repo_source', 'manual')
        ->assertSet('runtime', 'php:8.3')
        ->assertSet('name', 'PHP demo');
});

test('load laravel demo prefills the form', function () {
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->call('loadLaravelDemo')
        ->assertSet('git_repository_url', 'shaferllc/dply-demo-laravel-function')
        ->assertSet('git_branch', 'master')
        ->assertSet('repo_source', 'manual')
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
        ->set('repo_source', 'manual')
        ->set('git_repository_url', '')
        ->call('create')
        ->assertHasErrors(['name', 'git_repository_url']);
});

test('preselects the newest healthy digitalocean credential', function () {
    ProviderCredential::query()->create([
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'provider' => 'digitalocean',
        'name' => 'stale',
        'credentials' => ['token' => 'dop_v1_stale'],
        'validation_error' => 'DigitalOcean API failed to validate token: Unable to authenticate you',
    ]);

    $healthy = ProviderCredential::query()->create([
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'provider' => 'digitalocean',
        'name' => 'aug_19',
        'credentials' => ['token' => 'dop_v1_ok'],
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->assertSet('provider_credential_id', $healthy->id)
        ->assertSee('Can’t connect');
});

test('create rejects an unhealthy digitalocean credential', function () {
    $stale = ProviderCredential::query()->create([
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'provider' => 'digitalocean',
        'name' => 'stale',
        'credentials' => ['token' => 'dop_v1_stale'],
        'validation_error' => 'DigitalOcean API failed to validate token: Unable to authenticate you',
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('name', 'Laravel demo')
        ->set('repo_source', 'manual')
        ->set('git_repository_url', 'shaferllc/dply-demo-laravel-function')
        ->set('git_branch', 'master')
        ->set('runtime', 'php:8.4')
        ->set('region', 'nyc1')
        ->set('provider_credential_id', $stale->id)
        ->call('create')
        ->assertHasErrors(['provider_credential_id']);

    expect(Site::query()->where('organization_id', $this->org->id)->exists())->toBeFalse();
});

test('happy path creates function and redirects', function () {
    Bus::fake();
    withCredential($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('name', 'Orders API')
        ->set('repo_source', 'manual')
        ->set('git_repository_url', 'acme/orders')
        ->set('git_branch', 'main')
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
        ->set('repo_source', 'manual')
        ->set('git_repository_url', 'acme/detected')
        ->set('git_branch', 'main')
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
        ->set('repo_source', 'manual')
        ->set('git_repository_url', 'acme/api')
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
        ->set('git_repository_url', 'acme/api')
        ->set('git_branch', 'main')
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
        ->set('git_repository_url', 'acme/api')
        ->set('git_branch', 'main')
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
        ->set('git_repository_url', 'acme/api')
        ->set('git_branch', 'main')
        ->call('detectFromRepository')
        ->assertSet('runtime', 'go:1.22');
});

test('loading repositories does not select the first one', function () {
    withCredential($this->user, $this->org);
    $account = SocialAccount::query()->create([
        'user_id' => $this->user->id,
        'provider' => 'github',
        'provider_id' => '12345',
        'label' => 'Github token - deploy4',
        'nickname' => 'deploy4',
        'access_token' => encrypt('t'),
    ]);
    fakeLinkedGitRepositories($account->id, [
        ['label' => 'Chronograph/Cachet', 'url' => 'https://github.com/Chronograph/Cachet.git', 'branch' => 'main'],
        ['label' => 'acme/web', 'url' => 'https://github.com/acme/web.git', 'branch' => 'develop'],
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->assertSet('repo_source', 'provider')
        ->assertSet('source_control_account_id', $account->id)
        ->assertSet('repository_selection', '')
        ->assertSet('git_repository_url', '')
        ->assertSet('name', '')
        ->assertSee('Select repository')
        ->set('repository_selection', 'https://github.com/acme/web.git')
        ->assertSet('git_repository_url', 'https://github.com/acme/web.git')
        ->assertSet('git_branch', 'develop')
        ->assertSet('name', 'web');
});

test('pasted repository url is rematched when switching to a connected account', function () {
    Http::fake();
    withCredential($this->user, $this->org);
    $account = SocialAccount::query()->create([
        'user_id' => $this->user->id,
        'provider' => 'github',
        'provider_id' => '12345',
        'label' => 'github:acme',
        'nickname' => 'acme',
        'access_token' => encrypt('t'),
    ]);
    fakeLinkedGitRepositories($account->id, [
        ['label' => 'acme/api', 'url' => 'https://github.com/acme/api.git', 'branch' => 'main'],
        ['label' => 'acme/web', 'url' => 'https://github.com/acme/web.git', 'branch' => 'develop'],
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->assertSet('repository_selection', '')
        ->set('repo_source', 'manual')
        ->set('git_repository_url', 'https://github.com/acme/web.git')
        ->set('repo_source', 'provider')
        ->assertSet('repository_selection', 'https://github.com/acme/web.git')
        ->assertSet('git_repository_url', 'https://github.com/acme/web.git')
        ->assertSet('name', 'web');
});

test('detect from repository leaves dropdown on auto when nothing detected', function () {
    withCredential($this->user, $this->org);

    // An empty checkout — no framework markers, no raw main() entry file.
    fakeServerlessCheckout(fn (string $dir) => null);

    Livewire::actingAs($this->user)
        ->test(ServerlessCreate::class)
        ->set('git_repository_url', 'acme/empty')
        ->set('git_branch', 'main')
        ->call('detectFromRepository')
        ->assertSet('runtime', 'auto')
        ->assertSee('No runtime detected');
});

/**
 * Bind a fake {@see ServerlessRepositoryCheckout} that resolves to a local
 * fixture directory instead of cloning over the network.
 */
/**
 * Bind a {@see SourceControlRepositoryBrowser} that lists the given repos for
 * a linked SocialAccount without hitting the provider API.
 *
 * @param  list<array{label: string, url: string, branch: string}>  $repositories
 */
function fakeLinkedGitRepositories(string $accountId, array $repositories): void
{
    app()->instance(SourceControlRepositoryBrowser::class, new class($accountId, $repositories) extends SourceControlRepositoryBrowser
    {
        /**
         * @param  list<array{label: string, url: string, branch: string}>  $repositories
         */
        public function __construct(public string $accountId, public array $repositories)
        {
            parent::__construct();
        }

        public function accountsForUser($user): array
        {
            return [['id' => $this->accountId, 'provider' => 'github', 'label' => 'Github token - deploy4', 'kind' => 'oauth']];
        }

        public function repositoriesForAccount($account): array
        {
            return $this->repositories;
        }
    });
}

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
        public function checkout(
            string $workspaceKey = '',
            string $repositoryUrl = '',
            string $branch = 'main',
            string $subdirectory = '',
            int|string|null $userId = null,
            ?string $sourceControlAccountId = null,
            ?string $refKind = null,
        ): array {
            return [
                'workspace_path' => $this->dir,
                'repository_path' => $this->dir,
                'working_directory' => $this->dir,
                'output' => '',
                'branch' => $branch !== '' ? $branch : 'main',
            ];
        }

        public function cleanup(string $workspacePath): void {}
    });

    return $dir;
}
