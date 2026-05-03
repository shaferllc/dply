<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderCredential;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Lists the sites in the fleet that are good candidates for Fly.io edge
 * deployment — Node and static workloads, since the latency story is
 * weakest for stateful PHP/Ruby/Python apps and the cost/lat tradeoff
 * is best for stateless edge-served code.
 *
 *   dply:fly:eligibility [--json] [--connected-only]
 *
 * Two views:
 *   - default: every Node/static site with a one-line summary
 *   - --connected-only: filters to orgs that already have a Fly credential,
 *     so operators can see which sites still need to be ramped onto Fly
 *     after the initial credential connect.
 */
class FlyEligibilityCommand extends Command
{
    protected $signature = 'dply:fly:eligibility
        {--json : Output as JSON}
        {--connected-only : Only include orgs with a Fly.io credential}';

    protected $description = 'Lists Node/static sites that could deploy to Fly.io edge.';

    public function handle(): int
    {
        $orgsWithFly = ProviderCredential::query()
            ->where('provider', 'fly_io')
            ->pluck('organization_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = Site::query()
            ->whereIn('runtime', ['node', 'static'])
            ->with('server:id,name', 'organization:id,name');

        if ($this->option('connected-only')) {
            $query->whereIn('organization_id', $orgsWithFly);
        }

        $sites = $query->orderBy('organization_id')->orderBy('name')->get();

        $rows = $sites->map(fn (Site $s): array => [
            'site' => $s->name,
            'runtime' => $s->runtime,
            'organization' => $s->organization?->name ?? '—',
            'server' => $s->server?->name ?? '—',
            'org_has_fly' => in_array($s->organization_id, $orgsWithFly, true),
        ])->all();

        $payload = [
            'total_eligible' => count($rows),
            'orgs_connected_to_fly' => count($orgsWithFly),
            'sites' => $rows,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Fly.io edge eligibility</>');
        $this->line(sprintf(
            '  %d Node/static %s, %d %s already connected to Fly',
            $payload['total_eligible'],
            trans_choice('{1} site|[2,*] sites', $payload['total_eligible']),
            $payload['orgs_connected_to_fly'],
            trans_choice('{1} org|[2,*] orgs', $payload['orgs_connected_to_fly']),
        ));
        $this->newLine();

        if ($rows === []) {
            $this->line('<fg=gray>No eligible sites found.</>');

            return self::SUCCESS;
        }

        $this->table(
            ['site', 'runtime', 'organization', 'server', 'org connected?'],
            array_map(fn (array $r): array => [
                $r['site'],
                $r['runtime'],
                $r['organization'],
                $r['server'],
                $r['org_has_fly'] ? 'yes' : 'no',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
