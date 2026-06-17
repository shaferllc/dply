<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Env;

use App\Mcp\Concerns\MutatesSiteEnv;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class SetSiteEnvVar extends AbstractDplyTool
{
    use MutatesSiteEnv;

    protected string $name = 'set_site_env_var';

    protected string $description = <<<'TXT'
        Set (create or update) a single environment variable on a site and push
        the change to the server. The .env is validated before writing; an app-
        breaking value (e.g. an empty APP_KEY) is refused. The push is queued and
        debounced — call repeatedly to set several keys and the pushes coalesce.
        Returns an `operation_id`; poll `get_operation_status` until it settles.
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
                ->description('The environment variable name, e.g. MAIL_FROM_ADDRESS.')
                ->required(),
            'value' => $schema->string()
                ->description('The value to set.')
                ->required(),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'key' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'value' => ['present', 'string'],
        ]);

        $site = $this->resolveSite($data['site_id'], $organization);

        ['run' => $run, 'coalesced' => $coalesced] = $this->upsertSiteEnv(
            $site,
            [$data['key'] => $data['value']],
            $this->token()->user_id,
        );

        return Response::json([
            'status' => 'queued',
            'message' => "Set {$data['key']} and queued an environment push.",
            'site_id' => $site->id,
            'operation_id' => $run->id,
            'operation_kind' => 'env_push',
            'coalesced' => $coalesced,
            'poll_with' => 'get_operation_status',
        ]);
    }
}
