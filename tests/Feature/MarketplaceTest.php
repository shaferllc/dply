<?php

namespace Tests\Feature;

use App\Livewire\Marketplace\Index;
use App\Models\MarketplaceItem;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\User;
use App\Models\WebserverTemplate;
use Database\Seeders\MarketplaceItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketplaceItemSeeder::class);
    }

    public function test_guest_cannot_view_marketplace(): void
    {
        $this->get(route('marketplace.index'))->assertRedirect();
    }

    public function test_authenticated_user_can_view_marketplace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('marketplace.index'))
            ->assertOk()
            ->assertSee('Marketplace')
            ->assertSee('Saved commands');
    }

    public function test_org_admin_can_import_webserver_recipe(): void
    {
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
    }

    public function test_org_member_cannot_import_webserver_recipe(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        session(['current_organization_id' => $org->id]);

        $item = MarketplaceItem::query()->where('slug', 'nginx-laravel-php')->firstOrFail();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('importWebserverTemplate', $item->id);

        $this->assertEquals(0, WebserverTemplate::query()->where('organization_id', $org->id)->count());
    }

    public function test_user_can_import_deploy_marketplace_item_as_recipe(): void
    {
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

        $this->assertNotNull($recipe, 'A ServerRecipe row should have been created from the marketplace import.');
        $this->assertStringContainsString('git pull', (string) $recipe->script);
    }

    public function test_user_can_import_server_recipe_to_server_saved_commands(): void
    {
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
    }
}
