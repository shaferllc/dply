<?php

namespace Tests\Feature\WebserverTemplatesTest;

use App\Livewire\Settings\WebserverTemplates;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebserverTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guest cannot view webserver templates', function () {
    $org = Organization::factory()->create();

    $this->get(route('organizations.webserver-templates', $org))->assertRedirect();
});

test('org member can view webserver templates page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    Livewire::actingAs($user)
        ->test(WebserverTemplates::class, ['organization' => $org])
        ->assertOk()
        ->assertSee('Webserver templates');
});

test('org admin can create template', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    $content = "# Dply webserver template — do not remove\nserver { listen 80; }\n";

    Livewire::actingAs($user)
        ->test(WebserverTemplates::class, ['organization' => $org])
        ->set('label', 'My default')
        ->set('content', $content)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('webserver_templates', [
        'organization_id' => $org->id,
        'label' => 'My default',
    ]);
});

test('org member cannot save template', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    $content = "# Dply webserver template — do not remove\nserver { listen 80; }\n";

    Livewire::actingAs($user)
        ->test(WebserverTemplates::class, ['organization' => $org])
        ->set('label', 'X')
        ->set('content', $content)
        ->call('save')
        ->assertForbidden();
});

test('org admin can delete template', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    $template = WebserverTemplate::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(WebserverTemplates::class, ['organization' => $org])
        ->call('delete', $template->id);

    $this->assertDatabaseMissing('webserver_templates', ['id' => $template->id]);
});
