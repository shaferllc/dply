<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Billing;

use App\Livewire\Billing\Analytics;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('billing analytics page renders for org admin', function () {
    $admin = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($admin->id, ['role' => 'admin']);

    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
    ]);

    Livewire::actingAs($admin)
        ->test(Analytics::class, ['organization' => $org])
        ->assertOk()
        ->assertSee('Billing analytics')
        ->assertSee('Spend by category')
        ->assertSee('Historical spend trend')
        ->assertSee('MRR')
        ->assertSee('Projected month-end')
        ->assertSee('Stripe sync audit log')
        ->assertSee('Edge sites')
        ->assertSee('Managed products')
        ->assertSee('BYO server fleet')
        ->assertSee('Invoice history')
        ->assertDontSee('Still to add later');
});

test('billing analytics shows edge usage when snapshots exist', function () {
    $admin = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($admin->id, ['role' => 'admin']);

    $server = Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);
    $site = Site::factory()->for($org)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(2),
    ]);

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => 12_500,
        'bytes_egress' => 1024 ** 3,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    Livewire::actingAs($admin)
        ->test(Analytics::class, ['organization' => $org])
        ->assertSee($site->name)
        ->assertSee('12,500')
        ->assertSee('Platform fee');
});

test('billing analytics requires org update permission', function () {
    $member = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($member)
        ->test(Analytics::class, ['organization' => $org])
        ->assertForbidden();
});
