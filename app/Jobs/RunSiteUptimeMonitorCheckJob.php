<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use App\Services\Sites\UptimeProbeWorkerResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RunSiteUptimeMonitorCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, WritesConsoleAction;

    public int $timeout = 25;

    private const CONNECT_TIMEOUT_SECONDS = 4;

    private const HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(
        public string $siteUptimeMonitorId,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'console-action:uptime_check:'.$this->siteUptimeMonitorId;
    }

    protected function consoleSubject(): Model
    {
        $monitor = SiteUptimeMonitor::query()->with('site')->findOrFail($this->siteUptimeMonitorId);

        return $monitor->site;
    }

    protected function consoleKind(): string
    {
        return 'uptime_check';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Seed a queued console_actions row before dispatch so the page-top banner
     * appears instantly rather than after the worker picks up the job. Mirrors
     * the seed pattern used elsewhere (see Sites/Settings::seedQueuedConsoleAction).
     */
    public static function dispatchWithConsoleAction(
        Site $site,
        SiteUptimeMonitor $monitor,
        ?string $userId = null,
    ): void {
        if (! config('site_uptime.enabled', true)) {
            return;
        }

        ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        ConsoleAction::query()->create([
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'kind' => 'uptime_check',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => $userId,
            'output' => [
                'v' => (int) config('console_actions.current_version', 1),
                'lines' => [],
            ],
        ]);

        self::dispatch($monitor->id, $userId)->onQueue(self::queueForMonitor($monitor));
    }

    /**
     * Horizon queue this monitor's check runs on — the probe worker's queue
     * (regional egress), or the central `default` queue when it has none.
     */
    public static function queueForMonitor(SiteUptimeMonitor $monitor): string
    {
        return app(UptimeProbeWorkerResolver::class)->queueFor($monitor->probe_worker);
    }

    public function handle(SiteUptimeCheckUrlResolver $resolver, NotificationPublisher $notificationPublisher): void
    {
        if (! config('site_uptime.enabled', true)) {
            return;
        }

        $monitor = SiteUptimeMonitor::query()->with('site')->find($this->siteUptimeMonitorId);
        if (! $monitor || ! $monitor->site) {
            return;
        }

        $site = $monitor->site;
        $previousOk = $monitor->last_ok;

        $emit = $this->beginConsoleAction();
        $regionLabel = (string) (config('site_uptime.probe_regions.'.$monitor->probe_region) ?? $monitor->probe_region);
        $emit->step('uptime', sprintf('%s · %s', $monitor->label, $regionLabel));

        $url = $resolver->resolveFullUrl($site, $monitor);
        if ($url === null) {
            $reason = __('No public URL could be resolved for this site.');
            $monitor->update([
                'last_checked_at' => now(),
                'last_ok' => false,
                'last_http_status' => null,
                'last_latency_ms' => null,
                'last_error' => $reason,
            ]);
            $emit->error($reason);
            $this->failConsoleAction($reason);

            $this->maybePublishUptimeTransition(
                $monitor->fresh(),
                $site,
                $previousOk,
                false,
                null,
                null,
                0,
                $notificationPublisher
            );

            return;
        }

        $started = microtime(true);

        $attempts = [$url];
        if (str_starts_with(strtolower($url), 'https://')) {
            $attempts[] = 'http://'.substr($url, strlen('https://'));
        }

        $ok = false;
        $status = null;
        $error = null;

        foreach ($attempts as $attemptUrl) {
            $emit->info('GET '.$attemptUrl);
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->get($attemptUrl);
                $status = $response->status();
                $ok = $response->successful();
                $error = null;
                if ($ok) {
                    $emit->success('HTTP '.$status);
                    break;
                }
                $emit->warn('HTTP '.$status);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $ok = false;
                $emit->warn(Str::limit($e->getMessage(), 200, ''));
            }
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        if ($latency < 0) {
            $latency = 0;
        }

        $monitor->update([
            'last_checked_at' => now(),
            'last_ok' => $ok,
            'last_http_status' => $status,
            'last_latency_ms' => $latency,
            'last_error' => $error !== null && $error !== '' ? Str::limit($error, 500, '') : null,
        ]);

        if ($ok) {
            $emit->success(sprintf('OK · HTTP %d · %d ms', $status, $latency));
            $this->completeConsoleAction();
        } else {
            $summary = $status !== null
                ? sprintf('Failed · HTTP %d · %d ms', $status, $latency)
                : sprintf('Failed · %s · %d ms', $error ?? 'no response', $latency);
            $emit->error($summary);
            $this->failConsoleAction($error ?? ($status !== null ? 'HTTP '.$status : 'failed'));
        }

        $this->maybePublishUptimeTransition(
            $monitor->fresh(),
            $site,
            $previousOk,
            $ok,
            $url,
            $status,
            $latency,
            $notificationPublisher
        );
    }

    private function maybePublishUptimeTransition(
        SiteUptimeMonitor $monitor,
        Site $site,
        ?bool $previousOk,
        bool $newOk,
        ?string $checkedUrl,
        ?int $httpStatus,
        int $latencyMs,
        NotificationPublisher $notificationPublisher,
    ): void {
        if (! config('site_uptime.notify_on_transitions', true)) {
            return;
        }

        $site = $site->fresh(['server']);
        if (! $site?->server) {
            return;
        }

        $wentDown = ($previousOk === null || $previousOk === true) && $newOk === false;
        $recovered = $previousOk === false && $newOk === true;
        if (! $wentDown && ! $recovered) {
            return;
        }

        $state = $wentDown ? 'down' : 'recovered';
        $label = (string) $monitor->label;

        $notificationPublisher->publish(
            eventKey: 'site.uptime',
            subject: $site,
            title: '['.config('app.name').'] '.$site->name.' — '.$label.' '.($wentDown ? __('down') : __('recovered')),
            body: $checkedUrl !== null && $checkedUrl !== '' ? 'URL: '.$checkedUrl : null,
            url: route('sites.monitor', [$site->server, $site], absolute: true),
            metadata: [
                'site_id' => $site->id,
                'site_uptime_monitor_id' => $monitor->id,
                'state' => $state,
                'monitor_label' => $label,
                'last_http_status' => $httpStatus,
                'last_latency_ms' => $latencyMs,
                'checked_url' => $checkedUrl,
            ],
        );
    }
}
