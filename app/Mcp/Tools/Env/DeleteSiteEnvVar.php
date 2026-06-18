<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Env;

use App\Mcp\Concerns\MutatesSiteEnv;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class DeleteSiteEnvVar extends AbstractDplyTool
{
    use MutatesSiteEnv;

    protected string $name = 'delete_site_env_var';

    protected string $description = <<<'TXT'
        Remove an environment variable from a site and push the change to the
        server. No-op (no push) if the key isn't set. Returns an `operation_id`
        to poll with `get_operation_status` when a push is queued.
        TXT;

    protected string $ability = 'sites.write';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()
                ->description('The site id (or slug).')
                ->required(),
            'key' => $schema->string()
                ->description('The environment variable name to remove.')
                ->required(),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'key' => ['required', 'string'],
        ]);

        $site = $this->resolveSite($data['site_id'], $organization);

        $result = $this->deleteSiteEnv($site, $data['key'], $this->token()->user_id);

        if ($result === null) {
            return Response::json([
                'status' => 'noop',
                'message' => "{$data['key']} is not set on this site; nothing changed.",
                'site_id' => $site->id,
            ]);
        }

        return Response::json([
            'status' => 'queued',
            'message' => "Removed {$data['key']} and queued an environment push.",
            'site_id' => $site->id,
            'operation_id' => $result['run']->id,
            'operation_kind' => 'env_push',
            'coalesced' => $result['coalesced'],
            'poll_with' => 'get_operation_status',
        ]);
    }
}
