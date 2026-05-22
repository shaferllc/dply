<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

class FeatureSetCommand extends Command
{
    protected $signature = 'feature:set
        {flag : The flag name, e.g. provider.aws or global.signups_open}
        {--org= : Organization slug to scope the override to (omit for global.* flags)}
        {--on : Activate the flag for this scope}
        {--off : Deactivate the flag for this scope}
        {--reason= : Why this override was set (written to AuditLog)}';

    protected $description = 'Set a per-org Pennant flag override with audit trail.';

    public function handle(): int
    {
        $flag = $this->argument('flag');
        $on = (bool) $this->option('on');
        $off = (bool) $this->option('off');
        $orgSlug = $this->option('org');
        $reason = $this->option('reason');

        if ($on === $off) {
            $this->error('Pass exactly one of --on or --off.');

            return self::FAILURE;
        }

        if (! array_key_exists($flag, $this->definedFlags())) {
            $this->error("Unknown flag: {$flag}. Add it to config/features.php first.");

            return self::FAILURE;
        }

        $isGlobal = str_starts_with($flag, 'global.');
        $scope = null;

        if ($isGlobal) {
            if ($orgSlug !== null) {
                $this->error('global.* flags are app-wide; do not pass --org.');

                return self::FAILURE;
            }
        } else {
            if ($orgSlug === null) {
                $this->error('Non-global flags require --org=<slug>.');

                return self::FAILURE;
            }

            $scope = Organization::query()->where('slug', $orgSlug)->first();
            if (! $scope) {
                $this->error("Organization not found: {$orgSlug}");

                return self::FAILURE;
            }
        }

        if (! $reason) {
            $this->error('Pass --reason="..." so the override has audit context.');

            return self::FAILURE;
        }

        $previous = Feature::for($scope)->value($flag);

        if ($on) {
            Feature::for($scope)->activate($flag);
        } else {
            Feature::for($scope)->deactivate($flag);
        }

        $new = Feature::for($scope)->value($flag);

        if ($scope instanceof Organization) {
            AuditLog::log(
                organization: $scope,
                user: null,
                action: 'feature.override',
                subject: null,
                oldValues: ['flag' => $flag, 'value' => $previous],
                newValues: ['flag' => $flag, 'value' => $new, 'reason' => $reason],
            );
        }

        $scopeLabel = $isGlobal ? '(global)' : "org={$orgSlug}";
        $state = $on ? 'ON' : 'OFF';
        $this->info("Set {$flag} = {$state} for {$scopeLabel}.");

        return self::SUCCESS;
    }

    /**
     * Returns a map of "namespace.leaf" => default, mirroring config/features.php.
     */
    private function definedFlags(): array
    {
        $flat = [];
        foreach (config('features', []) as $namespace => $flags) {
            foreach ($flags as $leaf => $default) {
                $flat["$namespace.$leaf"] = $default;
            }
        }

        return $flat;
    }
}
