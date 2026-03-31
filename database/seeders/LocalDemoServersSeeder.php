<?php

namespace Database\Seeders;

use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local-only demo data: multiple teams, servers (Custom provider), and sites for UI testing.
 * Does not create real cloud resources; safe to destroy servers from the app index.
 */
class LocalDemoServersSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'tj@tjshafer.com')->first()
            ?? User::query()->first();

        if (! $user) {
            return;
        }

        $orgs = Organization::query()
            ->whereHas('users', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('name')
            ->get();

        if ($orgs->isEmpty()) {
            return;
        }

        foreach ($orgs as $org) {
            $this->seedDemoForOrganization($user, $org);
        }
    }

    protected function seedDemoForOrganization(User $user, Organization $org): void
    {
        if (Server::query()
            ->where('organization_id', $org->id)
            ->where('meta->local_demo', true)
            ->exists()) {
            return;
        }

        $teamNames = ['Platform', 'Edge Delivery', 'Internal'];
        $teams = [];

        foreach ($teamNames as $name) {
            $teams[] = Team::query()->firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'name' => $name,
                ],
                [
                    'slug' => Str::slug($name.'-'.$org->id),
                ]
            );
        }

        $fakeHosts = ['app', 'dply', 'packages', 'spiky-zebra', 'deadreckon', 'contests', 'api-gateway', 'worker-east'];

        foreach ($teams as $ti => $team) {
            $count = $ti === 0 ? 4 : 3;
            for ($i = 0; $i < $count; $i++) {
                $name = $fakeHosts[($ti * 4 + $i) % count($fakeHosts)].'-'.$team->id;
                $server = Server::factory()->create([
                    'user_id' => $user->id,
                    'organization_id' => $org->id,
                    'team_id' => $team->id,
                    'name' => $name,
                    'provider' => ServerProvider::Custom,
                    'provider_id' => null,
                    'ip_address' => fake()->ipv4(),
                    'ssh_port' => 22,
                    'ssh_user' => 'root',
                    'status' => fake()->randomElement([
                        Server::STATUS_READY,
                        Server::STATUS_READY,
                        Server::STATUS_READY,
                        Server::STATUS_PENDING,
                        Server::STATUS_ERROR,
                    ]),
                    'health_status' => fake()->randomElement([
                        Server::HEALTH_REACHABLE,
                        Server::HEALTH_REACHABLE,
                        Server::HEALTH_UNREACHABLE,
                        null,
                    ]),
                    'region' => fake()->randomElement(['nyc1', 'sfo3', 'lon1', 'fra1']),
                    'size' => 'demo',
                    'meta' => ['local_demo' => true],
                ]);

                $siteCount = fake()->numberBetween(0, 5);
                for ($s = 0; $s < $siteCount; $s++) {
                    $siteName = fake()->words(2, true).' site';
                    $project = Project::query()->create([
                        'organization_id' => $org->id,
                        'user_id' => $user->id,
                        'name' => $siteName,
                        'slug' => 'demo-'.Str::lower(Str::random(24)),
                        'kind' => Project::KIND_BYO_SITE,
                    ]);
                    $site = Site::factory()->make([
                        'server_id' => $server->id,
                        'user_id' => $user->id,
                        'organization_id' => $org->id,
                        'name' => $siteName,
                    ]);
                    $site->project_id = $project->id;
                    $site->save();
                    $project->update([
                        'name' => $site->name,
                        'slug' => $site->slug.'-'.$site->id,
                    ]);
                }
            }
        }

        ProviderCredential::query()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'name' => 'Local demo DO (fake token)',
            ],
            [
                'user_id' => $user->id,
                'provider' => 'digitalocean',
                'credentials' => ['api_token' => 'dop_v1_local_demo_'.fake()->sha1()],
            ]
        );
    }
}
