<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillOrganizationMemberships extends Command
{
    protected $signature = 'dply:backfill-organizations
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Create a default organization for each user with unassigned servers/credentials and assign them.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run – no changes will be made.');
        }

        $usersNeedingOrg = User::query()
            ->where(function ($q) {
                $q->whereHas('servers', fn ($q2) => $q2->whereNull('organization_id'))
                    ->orWhereHas('providerCredentials', fn ($q2) => $q2->whereNull('organization_id'));
            })
            ->get();

        if ($usersNeedingOrg->isEmpty()) {
            $this->info('No users with unassigned servers or credentials.');

            return self::SUCCESS;
        }

        $this->info('Found '.$usersNeedingOrg->count().' user(s) to backfill.');

        foreach ($usersNeedingOrg as $user) {
            $this->backfillUser($user, $dryRun);
        }

        return self::SUCCESS;
    }

    private function backfillUser(User $user, bool $dryRun): void
    {
        if ($user->organizations()->exists()) {
            $firstOrg = $user->organizations()->first();
            $this->assignServersAndCredentialsToOrg($user, $firstOrg, $dryRun);

            return;
        }

        $name = $user->name."'s Organization";
        $slug = Str::slug(Str::limit($user->name, 30)).'-'.Str::random(4);
        $base = Str::beforeLast($slug, '-');
        $i = 0;
        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        if (! $dryRun) {
            $org = Organization::create([
                'name' => $name,
                'slug' => $slug,
                'email' => $user->email,
            ]);
            $org->users()->attach($user->id, ['role' => 'owner']);
            $this->assignServersAndCredentialsToOrg($user, $org, false);
            $this->line("  Created org \"{$name}\" and assigned user as owner.");
        } else {
            $this->line("  Would create org \"{$name}\" and assign servers/credentials.");
        }
    }

    private function assignServersAndCredentialsToOrg(User $user, Organization $org, bool $dryRun): void
    {
        $servers = Server::where('user_id', $user->id)->whereNull('organization_id')->get();
        $credentials = ProviderCredential::where('user_id', $user->id)->whereNull('organization_id')->get();

        if (! $dryRun) {
            $servers->each(fn (Server $s) => $s->update(['organization_id' => $org->id]));
            $credentials->each(fn (ProviderCredential $c) => $c->update(['organization_id' => $org->id]));
        }

        if ($servers->count() > 0 || $credentials->count() > 0) {
            $this->line('    Assigned '.$servers->count().' server(s), '.$credentials->count().' credential(s) to org.');
        }
    }
}
