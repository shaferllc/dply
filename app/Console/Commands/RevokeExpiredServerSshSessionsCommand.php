<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Servers\ServerSshSessionManager;
use Illuminate\Console\Command;

class RevokeExpiredServerSshSessionsCommand extends Command
{
    protected $signature = 'dply:revoke-expired-ssh-sessions';

    protected $description = 'Revoke time-boxed contractor SSH sessions past their expiry.';

    public function handle(ServerSshSessionManager $manager): int
    {
        $count = $manager->revokeExpired();

        $this->info('Revoked '.$count.' expired SSH session(s).');

        return self::SUCCESS;
    }
}
