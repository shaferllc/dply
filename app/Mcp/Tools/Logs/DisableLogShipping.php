<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Logs;

use App\Actions\Servers\ManageServerLogShipping;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class DisableLogShipping extends AbstractDplyTool
{
    protected string $name = 'disable_log_shipping';

    protected string $description = <<<'TXT'
        Disable the dply Logs add-on on a server: uninstall the edge Vector agent
        (stops + removes the unit, config, and state, queued). Idempotent — a
        server with no agent is a no-op. Poll `get_log_shipping_status`; the agent
        record is removed once the uninstall succeeds.
        TXT;

    protected string $ability = 'commands.run';

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
        $action = app(ManageServerLogShipping::class);

        $action->disable($server);

        return Response::json($action->status($server->refresh()));
    }
}
