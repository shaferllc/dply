<?php

namespace App\Services\Insights\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

class SupervisorStartFixAction implements InsightFixActionInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @param  array<string, mixed> $params
     */
    public function preflight(Server $server, ?Site $site, InsightFinding $finding, array $params): ?string
    {
        if (! $server->isReady()) {
            return __('Server is not ready.');
        }
        if ($server->ip_address === null || $server->ip_address === '') {
            return __('Server has no IP address recorded.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $params
     */
    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $inline = <<<'BASH'
if systemctl start supervisor 2>/dev/null || systemctl start supervisord 2>/dev/null; then
  echo "started"
elif service supervisor start 2>/dev/null; then
  echo "started"
else
  echo "failed"
  exit 1
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-fix-supervisor-start', $inline, 60, true);

            return FixResult::success(mb_substr((string) $out->getBuffer(), 0, 2000));
        } catch (\Throwable $e) {
            return FixResult::failure($e->getMessage());
        }
    }
}
