<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgeGithubPreviewEnsurer;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Console\Command;

class EdgeEnsureGithubPreviewsCommand extends Command
{
    protected $signature = 'dply:edge:ensure-github-previews';

    protected $description = 'Connect GitHub webhooks on Edge sites so PR previews post Check Runs and summary comments';

    public function handle(EdgeGithubPreviewEnsurer $ensurer): int
    {
        if (FakeEdgeProvision::enabled()) {
            $this->error('DPLY_FAKE_EDGE is enabled. Disable fake edge before connecting production GitHub webhooks.');

            return self::FAILURE;
        }

        $hookBase = rtrim((string) config('app.url'), '/');
        $this->line('Webhook base URL: '.$hookBase.'/hooks/edge/{site}/github');
        $this->line('GitHub must reach this URL — use a public APP_URL in production.');
        $this->newLine();

        $results = $ensurer->ensureAllProductionSites();

        if ($results === []) {
            $this->line('No production Edge sites found.');

            return self::SUCCESS;
        }

        foreach ($results as $row) {
            $line = $row['slug'].': '.$row['message'];
            if ($row['connected']) {
                $this->info($line);
            } else {
                $this->warn($line);
            }
        }

        return self::SUCCESS;
    }
}
