<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Logs;

use App\Actions\Servers\ManageServerLogShipping;
use App\Exceptions\LogShippingException;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ResyncLogShipping extends AbstractDplyTool
{
    protected string $name = 'resync_log_shipping';

    protected string $description = <<<'TXT'
        Re-sync a server's dply Logs agent: re-render its Vector config and restart
        it (idempotent reinstall, queued). Use after changing enabled sources or
        when the aggregator endpoint changed so the box picks up the new
        destination. Poll `get_log_shipping_status` until status is `running`.
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

        try {
            $action->resync($server);
        } catch (LogShippingException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($action->status($server->refresh()));
    }
}
