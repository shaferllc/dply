<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Points a site's customer hostnames at its server by upserting A records
 * through the site's resolved DNS credential — the "Apply records" action on the
 * routing DNS tab. Provider API calls only (no SSH); streams progress into the
 * page-top banner so the operator watches each record land.
 *
 * Only hostnames that fall inside the resolved zone are touched; anything else
 * (an apex on a different registrar, a Cloudflare-proxied host) is reported and
 * skipped rather than guessed at.
 */
class ApplySiteDnsRecordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public string $siteId,
        public ?string $seededConsoleRunId = null,
        public ?string $userId = null,
    ) {}

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'dns_apply';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(): void
    {
        $site = Site::query()->with(['server', 'domains'])->find($this->siteId);
        if ($site === null || $site->server === null) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        $serverIp = trim((string) ($site->server->ip_address ?? ''));
        $zone = strtolower(trim((string) ($site->dns_zone ?: ($site->guessDnsZoneFromPrimaryHostname() ?? ''))));
        $credential = $site->dnsAutomationCredential();

        try {
            if ($serverIp === '') {
                throw new \RuntimeException('This server has no IP address yet.');
            }
            if ($zone === '') {
                throw new \RuntimeException('No DNS zone is set for this site — set one in Automation, or add records manually.');
            }
            if ($credential === null) {
                throw new \RuntimeException('No DNS credential controls '.$zone.' — add the records manually instead.');
            }

            $provider = SiteDnsProviderFactory::forCredential($credential);

            $applied = 0;
            $skipped = 0;
            foreach ($site->customerDomainHostnames() as $host) {
                $host = strtolower(trim((string) $host));
                if ($host === '') {
                    continue;
                }

                $inZone = $host === $zone || str_ends_with($host, '.'.$zone);
                if (! $inZone) {
                    $emit->step('dns', $host.' is not inside '.$zone.' — skipped (point it from its own registrar).');
                    $skipped++;

                    continue;
                }

                $name = $host === $zone ? '@' : rtrim(substr($host, 0, -(strlen($zone) + 1)), '.');
                $name = $name === '' ? '@' : $name;

                $emit->step('dns', 'A  '.$name.'  → '.$serverIp.'  (zone '.$zone.') …');
                $provider->upsertRecord($zone, 'A', $name, $serverIp);
                $applied++;
            }

            if ($applied === 0 && $skipped > 0) {
                $emit->error('No records were inside '.$zone.' — nothing applied.', 'dns');
                $this->failConsoleAction('No in-zone hostnames to apply.');

                return;
            }

            $emit->success(
                $applied.' record(s) pointed at '.$serverIp.($skipped > 0 ? ' ('.$skipped.' skipped)' : '')
                    .'. DNS can take a few minutes to propagate — then re-check.',
                'dns',
            );
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'dns');
            $this->failConsoleAction($e->getMessage());
            Log::warning('ApplySiteDnsRecordsJob failed', [
                'site_id' => $this->siteId,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
