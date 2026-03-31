<?php

namespace App\Events\Servers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerAuthorizedKeysSynced
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Server $server,
        public ?User $initiatedBy,
        public string $summary,
        public array $payload = [],
    ) {}
}
