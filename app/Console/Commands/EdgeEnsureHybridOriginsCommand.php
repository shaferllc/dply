<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Edge\EdgeHybridOriginEnsurer;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class EdgeEnsureHybridOriginsCommand extends Command
{
    protected $signature = 'dply:edge:ensure-hybrid-origins
                            {--convert= : Convert a static Edge site slug to hybrid}
                            {--origin-url= : Origin URL when using --convert}
                            {--deploy-worker : Redeploy the Edge worker after origin routing fix}';

    protected $description = 'Ensure hybrid Edge sites have origin auth, healthcheck, default proxy routes, and republish host maps';

    public function handle(EdgeHybridOriginEnsurer $ensurer): int
    {
        if (FakeEdgeProvision::enabled()) {
            $this->error('DPLY_FAKE_EDGE is enabled. Disable fake edge before ensuring hybrid origins.');

            return self::FAILURE;
        }

        $convertSlug = trim((string) $this->option('convert'));
        $originUrl = trim((string) $this->option('origin-url'));

        if ($convertSlug !== '') {
            if ($originUrl === '') {
                $this->error('Pass --origin-url= when using --convert=');

                return self::FAILURE;
            }

            $site = Site::query()->where('slug', $convertSlug)->first();
            if ($site === null) {
                $this->error('Edge site not found: '.$convertSlug);

                return self::FAILURE;
            }

            try {
                $ensurer->convertStaticSite($site, $originUrl);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info('Converted '.$convertSlug.' to hybrid with origin '.$originUrl);
        }

        $results = $ensurer->ensureAllHybridSites();

        if ($results === [] && $convertSlug === '') {
            $this->line('No hybrid Edge sites with an origin URL found.');

            return self::SUCCESS;
        }

        foreach ($results as $row) {
            $health = $row['healthcheck'];
            $status = ($health['ok'] ?? false) ? 'OK' : 'FAIL';
            $suffix = $row['updated'] ? ' (metadata updated)' : '';
            $this->line(sprintf(
                '%s%s — healthcheck %s: %s',
                $row['slug'],
                $suffix,
                $status,
                $health['message'] ?? '',
            ));
        }

        if ($this->option('deploy-worker')) {
            $exitCode = Artisan::call('edge:worker:deploy');
            if ($exitCode !== 0) {
                $this->error(trim(Artisan::output()) ?: 'edge:worker:deploy failed.');

                return self::FAILURE;
            }
            $this->info('Edge worker redeployed.');
        }

        return self::SUCCESS;
    }
}
