<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logs\LogExplorerWindowedFetchTest;

use App\Livewire\Servers\Concerns\ManagesServerLogExplorer;
use App\Models\Server;

/**
 * Plain host exposing the explorer trait's protected fetch, so the windowed-fetch
 * tests can exercise it without booting a Livewire component or touching
 * ClickHouse.
 *
 * Lives in its own file because PSR-4 maps this namespace to the directory of the
 * same name — declaring it inside LogExplorerWindowedFetchTest.php made composer
 * skip the file with a "does not comply with psr-4 autoloading standard" notice.
 */
class ExplorerHarness
{
    use ManagesServerLogExplorer;

    public Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /** @return array<string,mixed> */
    public function fetch(): array
    {
        return $this->loadLogExplorer();
    }
}
