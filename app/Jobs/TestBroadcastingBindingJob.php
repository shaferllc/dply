<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\RealtimeApp;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Realtime\RealtimeBackendFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Tests a site's managed `broadcasting` binding by publishing a harmless test
 * event to the relay — straight from the control plane, no SSH. The relay is a
 * public Pusher-compatible endpoint, so the meaningful signal is "is the relay
 * live AND does it accept this app's credentials AND is the app enabled?", all
 * of which a single authenticated publish proves in one round-trip:
 *
 *   POST https://<host>/apps/<app-id>/events
 *     X-Dply-Key / X-Dply-Secret   (the app's credentials)
 *     {name, channel, data}        (Pusher-compatible publish body)
 *
 * A 2xx means the worker resolved the app from KV, the credentials verified,
 * and the event fanned out to the app's hub. The result streams into the
 * page-top console banner and is recorded on the binding (config.connectivity +
 * last_error) so the Resources card flips its "Not checked" badge to
 * Reachable / Unreachable — the same shape {@see ValidateBindingConnectivityJob}
 * writes for the TCP-probe path (which BYO broadcasting bindings still use).
 */
class TestBroadcastingBindingJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $bindingId,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        $binding = SiteBinding::query()->find($this->bindingId);
        $action = ConsoleAction::query()->find($this->consoleActionId);

        if ($site === null || $binding === null || $action === null || $binding->type !== 'broadcasting') {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);

        try {
            // Local dev runs against a cache-backed fake relay with no HTTP
            // endpoint — a real publish would always time out. Treat it as a
            // pass so the badge stays honest in development.
            if (RealtimeBackendFactory::fakeEnabled()) {
                $emit->info('Local fake relay is active — publish skipped, treating as reachable.', 'publish');
                $this->finish($emit, $binding, true, 'Local fake relay (publish skipped).');

                return;
            }

            $app = RealtimeApp::query()->find($binding->target_id);
            if (! $app instanceof RealtimeApp) {
                $emit->warn('This broadcasting binding has no managed relay app to test.', 'publish');
                $this->finish($emit, $binding, false, 'No managed relay app found for this binding.', failed: true);

                return;
            }

            if (! $app->isActive()) {
                $detail = sprintf('The relay app "%s" is %s, not active.', (string) $app->name, (string) $app->status);
                $emit->warn($detail, 'publish');
                $this->finish($emit, $binding, false, $detail, failed: true);

                return;
            }

            if (trim($app->host()) === '') {
                $emit->warn('The relay app has no host configured — nothing to reach.', 'publish');
                $this->finish($emit, $binding, false, 'Relay host is not configured.', failed: true);

                return;
            }

            $emit->step('publish', sprintf('Publishing a test event to %s …', $app->publishEndpoint()));

            $response = Http::withHeaders($app->statsAuthHeaders())
                ->acceptJson()
                ->timeout(10)
                ->post($app->publishEndpoint(), [
                    'name' => 'dply:test',
                    'channel' => 'dply-test',
                    'data' => ['source' => 'dply-resources-test'],
                ]);

            if ($response->successful()) {
                $emit->success('publish', sprintf('Reachable — %s accepted the test event (HTTP %d).', $app->host(), $response->status()));
                $this->finish($emit, $binding, true, null);

                return;
            }

            $snippet = trim(mb_substr((string) $response->body(), 0, 300));
            $detail = sprintf('The relay rejected the publish: HTTP %d%s', $response->status(), $snippet !== '' ? ' — '.$snippet : '');
            $emit->error($detail, 'publish');
            $this->finish($emit, $binding, false, mb_substr($detail, 0, 1000), failed: true);
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $emit->error('Broadcasting test failed: '.$message, 'publish');
            $this->finish($emit, $binding, false, 'Broadcasting test did not complete: '.$message, failed: true);
        }
    }

    /**
     * Record the outcome on the binding (same connectivity shape the TCP-probe
     * path writes, so the Resources card badge reads it unchanged) and close out
     * the console run.
     */
    private function finish(ConsoleEmitter $emit, SiteBinding $binding, bool $ok, ?string $error, bool $failed = false): void
    {
        $config = $binding->config;
        $config['connectivity'] = [
            'ok' => $ok,
            'checked_at' => now()->toIso8601String(),
            'detail' => $error,
        ];
        $binding->forceFill([
            'config' => $config,
            'last_error' => $ok ? null : $error,
        ])->save();

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $failed ? ConsoleAction::STATUS_FAILED : ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => $failed ? $error : null,
            'updated_at' => now(),
        ]);
    }
}
