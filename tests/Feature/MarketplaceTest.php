<?php


namespace Tests\Feature\MarketplaceTest;
use App\Livewire\Marketplace\Index;
use App\Models\MarketplaceItem;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\User;
use App\Models\WebserverTemplate;
use Database\Seeders\MarketplaceItemSeeder;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

beforeEach(function () {
    $this->seed(MarketplaceItemSeeder::class);
});

test('guest cannot view marketplace', function () {
    $this->get(route('marketplace.index'))->assertRedirect();
});

test('authenticated user can view marketplace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('Marketplace')
        ->assertSee('Saved commands');
});

test('org admin can import webserver recipe', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);

    $item = MarketplaceItem::query()->where('slug', 'nginx-laravel-php')->firstOrFail();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('importWebserverTemplate', $item->id);

    $this->assertDatabaseHas('webserver_templates', [
        'organization_id' => $org->id,
        'label' => 'Laravel (PHP-FPM)',
    ]);
});

test('org member cannot import webserver recipe', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    $item = MarketplaceItem::query()->where('slug', 'nginx-laravel-php')->firstOrFail();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('importWebserverTemplate', $item->id);

    expect(WebserverTemplate::query()->where('organization_id', $org->id)->count())->toEqual(0);
});

test('user can import deploy marketplace item as recipe', function () {
    // Marketplace items typed RECIPE_DEPLOY_COMMAND used to write
    // their script into the server's `deploy_command` column. After
    // the /deploy + /recipes merge into /run, that column is gone
    // — the import lands as a ServerRecipe row instead, alongside
    // any other saved commands on this server.
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $item = MarketplaceItem::query()->where('slug', 'deploy-static-git')->firstOrFail();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('deployModalItemId', $item->id)
        ->set('deployServerId', $server->id)
        ->call('confirmDeployImport');

    $recipe = ServerRecipe::query()
        ->where('server_id', $server->id)
        ->latest('created_at')
        ->first();

    expect($recipe)->not->toBeNull('A ServerRecipe row should have been created from the marketplace import.');
    $this->assertStringContainsString('git pull', (string) $recipe->script);
});

test('user can import server recipe to server saved commands', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $item = MarketplaceItem::query()->where('slug', 'server-disk-usage-summary')->firstOrFail();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('serverRecipeModalItemId', $item->id)
        ->set('deployServerId', $server->id)
        ->call('confirmServerRecipeImport');

    $this->assertDatabaseHas('server_recipes', [
        'server_id' => $server->id,
        'name' => 'Disk usage summary',
    ]);

    $recipe = ServerRecipe::query()->where('server_id', $server->id)->firstOrFail();
    $this->assertStringContainsString('df -hT', $recipe->script);
});