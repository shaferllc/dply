<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Operator lever for the is_internal exemption flag. An internal org is
 * permanently exempt from the trial/soft/hard-pause ladder (trialState()
 * short-circuits to NoTrial), so dply's own control plane and staff orgs can
 * never bill-pause themselves. The flag is intentionally NOT mass-assignable,
 * so this command (or tinker) is the way to set it.
 */
class OrganizationInternalCommand extends Command
{
    protected $signature = 'dply:org:internal {org : organization id, slug, or name} {--off : clear the flag instead of setting it}';

    protected $description = 'Mark an organization internal (exempt from trial/pause) — or clear it with --off.';

    public function handle(): int
    {
        $needle = (string) $this->argument('org');

        $org = Organization::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();

        if ($org === null) {
            $this->error("No organization matched '{$needle}' by id, slug, or name.");

            return self::FAILURE;
        }

        $value = ! $this->option('off');
        $org->is_internal = $value;
        $org->save();

        $this->info(sprintf(
            '%s is_internal=%s — trialState=%s, canDeploy=%s',
            $org->name,
            $value ? 'true' : 'false',
            $org->fresh()->trialState()->value,
            $org->fresh()->canDeploy() ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }
}
