<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RunSiteUptimeMonitorCheckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 25;

    private const CONNECT_TIMEOUT_SECONDS = 4;

    private const HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(
        public string $siteUptimeMonitorId
    ) {}

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
        $url = $resolver->resolveFullUrl($site, $monitor);
        if ($url === null) {
            $monitor->update([
                'last_checked_at' => now(),
                'last_ok' => false,
                'last_http_status' => null,
                'last_latency_ms' => null,
                'last_error' => __('No public URL could be resolved for this site.'),
            ]);
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
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->get($attemptUrl);
                $status = $response->status();
                $ok = $response->successful();
                $error = null;
                if ($ok) {
                    break;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $ok = false;
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
