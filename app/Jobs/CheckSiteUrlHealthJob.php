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
        if (! $site || $site->status !== Site::STATUS_NGINX_ACTIVE) {
            return;
        }

        $domain = $site->primaryDomain();
        if (! $domain) {
            return;
        }

        $host = $domain->hostname;
        $url = 'https://'.$host;

        $ok = false;
        try {
            $ok = Http::timeout(8)->connectTimeout(4)->get($url)->successful();
        } catch (\Throwable) {
            try {
                $ok = Http::timeout(8)->connectTimeout(4)->get('http://'.$host)->successful();
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
