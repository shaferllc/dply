<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Logs;

use App\Actions\Servers\ManageServerLogShipping;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetLogShippingStatus extends AbstractDplyTool
{
    protected string $name = 'get_log_shipping_status';

    protected string $description = <<<'TXT'
        Read the dply Logs add-on state for a server: whether the edge Vector
        agent is installed, its status/version, which sources it collects, when
        the aggregator last saw logs (`last_seen_at`), and `destination` — where
        logs are shipped, or `blackhole` when no aggregator endpoint is configured
        (the agent runs healthy but discards everything). Use `list_servers` to
        find server ids.
        TXT;

    protected string $ability = 'servers.read';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'server_id' => $schema->string()
                ->description('The server id.')
                ->required(),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate(['server_id' => ['required', 'string']]);
        $server = $this->resolveServer($data['server_id'], $organization);

        return Response::json(app(ManageServerLogShipping::class)->status($server));
    }
}
