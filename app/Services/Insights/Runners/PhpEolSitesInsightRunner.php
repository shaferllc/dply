<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use Carbon\Carbon;

class PhpEolSitesInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        $eolMap = config('insights_eol', []);
        $candidates = [];

        foreach ($server->sites()->get() as $s) {
            $ver = $this->normalizePhpVersion((string) $s->php_version);
            if ($ver === null) {
                continue;
            }
            $eol = $eolMap[$ver] ?? null;
            if ($eol === null) {
                continue;
            }
            $eolAt = Carbon::parse($eol)->startOfDay();
            $candidates[] = ['site' => $s, 'eol' => $eolAt];
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, fn ($a, $b) => $a['eol'] <=> $b['eol']);
        $worstSite = $candidates[0]['site'];
        $worst = $candidates[0]['eol'];

        $past = $worst->isPast();

        return [
            new InsightCandidate(
                insightKey: 'php_eol_sites',
                dedupeHash: 'site_'.$worstSite->id,
                severity: $past ? InsightFinding::SEVERITY_CRITICAL : InsightFinding::SEVERITY_WARNING,
                title: $past
                    ? __('Site :name runs PHP :v (past EOL)', ['name' => $worstSite->name, 'v' => $worstSite->php_version])
                    : __('Site :name — PHP :v reaches EOL on :date', [
                        'name' => $worstSite->name,
                        'v' => $worstSite->php_version,
                        'date' => $worst->toDateString(),
                    ]),
                body: __('EOL reference dates are approximate; verify against php.net for your branch.'),
                meta: [
                    'site_id' => $worstSite->id,
                    'php_version' => $worstSite->php_version,
                    'eol_date' => $worst->toDateString(),
                ],
            ),
        ];
    }

    protected function normalizePhpVersion(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d+\.\d+)/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }
}
