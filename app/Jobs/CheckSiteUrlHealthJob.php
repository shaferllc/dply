<?php

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class CheckSiteUrlHealthJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 20;

    public function __construct(
        public int $siteId
    ) {}

    public function handle(): void
    {
        if (! config('dply.site_health_check_enabled', true)) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site || ! $site->isReadyForTraffic()) {
            return;
        }

        $domain = $site->primaryDomain();
        if (! $domain) {
            return;
        }

        $host = $domain->hostname;
        $url = 'https://'.$host;

        // "Up" here means the webserver answered authoritatively — including 401/403
        // for sites that gate traffic behind basic auth or a deny rule. Treating those
        // as down would mark every credential-protected site as unhealthy as soon as
        // someone enables htaccess. 5xx still counts as down: app stack is broken.
        $isUp = static fn (int $status): bool => ($status >= 200 && $status < 400)
            || in_array($status, [401, 403, 404, 405, 410], true);

        $ok = false;
        try {
            $ok = $isUp(Http::timeout(8)->connectTimeout(4)->get($url)->status());
        } catch (\Throwable) {
            try {
                $ok = $isUp(Http::timeout(8)->connectTimeout(4)->get('http://'.$host)->status());
            } catch (\Throwable) {
                $ok = false;
            }
        }

        $meta = $site->meta ?? [];
        $meta['site_health_last_check_at'] = now()->toIso8601String();
        $meta['site_health_last_ok'] = $ok;
        $meta['site_health_checked_url'] = $url;
        $site->update(['meta' => $meta]);
    }
}
