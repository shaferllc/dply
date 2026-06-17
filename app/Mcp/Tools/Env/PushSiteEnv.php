<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Env;

use App\Mcp\Concerns\MutatesSiteEnv;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class PushSiteEnv extends AbstractDplyTool
{
    use MutatesSiteEnv;

    protected string $name = 'push_site_env';

    protected string $description = <<<'TXT'
        Push the site's current staged .env to the server without changing it —
        useful to re-sync after edits or to force the live file to match. The
        push is queued and serialised per server. Returns an `operation_id` to
        poll with `get_operation_status`.
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
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        ['site_id' => $siteId] = $request->validate([
            'site_id' => ['required', 'string'],
        ]);

        $site = $this->resolveSite($siteId, $organization);

        ['run' => $run, 'coalesced' => $coalesced] = $this->pushSiteEnv($site, $this->token()->user_id);

        return Response::json([
            'status' => 'queued',
            'message' => 'Environment push queued.',
            'site_id' => $site->id,
            'operation_id' => $run->id,
            'operation_kind' => 'env_push',
            'coalesced' => $coalesced,
            'poll_with' => 'get_operation_status',
        ]);
    }
}
