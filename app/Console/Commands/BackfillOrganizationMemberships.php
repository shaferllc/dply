<?php

namespace App\Console\Commands;

use App\Actions\Organizations\EnsureUserHasWorkspaceOrganization;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;

class BackfillOrganizationMemberships extends Command
{
    protected $signature = 'dply:backfill-organizations
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Create a default workspace for users missing an organization and assign any unowned records.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run – no changes will be made.');
        }

        $usersNeedingOrg = User::query()
            ->where(function ($q) {
                $q->whereDoesntHave('organizations')
                    ->orWhereHas('servers', fn ($q2) => $q2->whereNull('organization_id'))
                    ->orWhereHas('providerCredentials', fn ($q2) => $q2->whereNull('organization_id'));
            })
            ->get();

        if ($usersNeedingOrg->isEmpty()) {
            $this->info('No users need organization backfill.');

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
        $existingOrg = $user->organizations()->orderByPivot('created_at')->orderBy('organizations.id')->first();
        $org = $existingOrg;

        if (! $org && $dryRun) {
            $this->line('  Would create workspace "'.EnsureUserHasWorkspaceOrganization::workspaceNameFor($user).'" and assign records.');

            return;
        }

        if (! $org) {
            $org = EnsureUserHasWorkspaceOrganization::run($user);
            $this->line('  Created workspace "'.$org->name.'" and assigned user as owner.');
        } else {
            $org->createDefaultTeamIfMissing();
            $org->attachUserToDefaultTeam($user);
        }

        $this->assignServersAndCredentialsToOrg($user, $org, $dryRun);
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
