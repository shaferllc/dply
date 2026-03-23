<?php

namespace App\Console\Commands;

use App\Services\Notifications\DeployDigestBuffer;
use Illuminate\Console\Command;

class FlushDeployDigestCommand extends Command
{
    protected $signature = 'dply:flush-deploy-digest';

    protected $description = 'Send batched deploy notification emails (when digest mode is enabled).';

    public function handle(): int
    {
        if ((int) config('dply.deploy_digest_hours', 0) <= 0) {
            return self::SUCCESS;
        }
        DeployDigestBuffer::flushAll();

        return self::SUCCESS;
    }
}
