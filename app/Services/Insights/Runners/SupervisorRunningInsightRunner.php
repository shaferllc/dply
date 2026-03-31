<?php

namespace App\Services\Insights\Runners;

use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

class SupervisorRunningInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        if (! $server->isReady() || $server->ip_address === null || $server->ip_address === '') {
            return [];
        }

        $inline = <<<'BASH'
if systemctl is-active --quiet supervisor 2>/dev/null; then
  echo "active"
elif systemctl is-active --quiet supervisord 2>/dev/null; then
  echo "active"
elif service supervisor status 2>&1 | grep -qiE 'running|active'; then
  echo "active"
else
  echo "inactive"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-supervisor-status', $inline, 25, false);
            $buf = strtolower(trim($out->getBuffer()));
        } catch (\Throwable $e) {
            Log::debug('insights.supervisor_check_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buf, 'active')) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'supervisor_running',
                dedupeHash: 'service_down',
                severity: 'warning',
                title: __('Supervisor is not running'),
                body: __('The Supervisor process manager does not appear to be active. Queues and workers may be down.'),
                meta: ['remote_snippet' => mb_substr($buf, 0, 500)],
            ),
        ];
    }
}
