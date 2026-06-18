<?php

namespace App\Modules\Insights\Services\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightFixActionInterface;
use App\Modules\Insights\Services\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Enable NTP via `timedatectl set-ntp true`. Restart-style action — no on-disk config
 * mutation, no backup required. The post-action recheck (problem-kind lifecycle) will
 * re-run the runner and confirm the synchronized=yes / NTP service=active state cleared.
 */
class EnableNtpFixAction implements InsightFixActionInterface
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
        if (blank($server->ip_address)) {
            return __('Server has no IP address recorded.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $params
     */
    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $script = <<<'BASH'
if ! command -v timedatectl >/dev/null 2>&1; then
  echo "no-timedatectl"
  exit 1
fi
timedatectl set-ntp true 2>&1
sleep 1
timedatectl status 2>/dev/null | grep -E 'NTP service|synchronized' || true
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-fix-enable-ntp', $script, 30, true);

            return FixResult::success(mb_substr((string) $out->getBuffer(), 0, 2000));
        } catch (\Throwable $e) {
            return FixResult::failure($e->getMessage());
        }
    }
}
