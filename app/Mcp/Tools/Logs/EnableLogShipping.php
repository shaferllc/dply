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

class EnableLogShipping extends AbstractDplyTool
{
    protected string $name = 'enable_log_shipping';

    protected string $description = <<<'TXT'
        Enable the dply Logs add-on on a VM server: install the edge Vector agent
        (over SSH, queued) so it ships host + service logs to dply. Optionally pass
        `sources` to choose which collectors are on (journald, web, php_fpm,
        site_app, auth); omit to use the defaults. The install runs asynchronously —
        poll `get_log_shipping_status` until status is `running` (or `failed`).
        Returns the post-dispatch status, including the shipping `destination`.
        TXT;

    protected string $ability = 'commands.run';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'server_id' => $schema->string()
                ->description('The server id.')
                ->required(),
            'sources' => $schema->object()
                ->description('Optional map of source key => bool to enable/disable collectors (journald, web, php_fpm, site_app, auth).'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'server_id' => ['required', 'string'],
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['boolean'],
        ]);

        $server = $this->resolveServer($data['server_id'], $organization);
        $action = app(ManageServerLogShipping::class);

        try {
            $action->enable($server, $data['sources'] ?? null);
        } catch (LogShippingException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($action->status($server->refresh()));
    }
}
