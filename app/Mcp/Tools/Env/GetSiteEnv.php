<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Env;

use App\Mcp\Concerns\MutatesSiteEnv;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetSiteEnv extends AbstractDplyTool
{
    use MutatesSiteEnv;

    protected string $name = 'get_site_env';

    protected string $description = <<<'TXT'
        Read a site's staged environment variables (the editable .env dply tracks
        for the site). Returns the keys; values are withheld unless `show_values`
        is true, since they often contain secrets. Reflects the staged copy that
        env edits and pushes operate on, not necessarily what is live on the box
        if a push is still pending.
        TXT;

    protected string $ability = 'sites.read';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()
                ->description('The site id (or slug).')
                ->required(),
            'show_values' => $schema->boolean()
                ->description('Include the (potentially secret) values. Defaults to false — keys only.'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'show_values' => ['nullable', 'boolean'],
        ]);

        $site = $this->resolveSite($data['site_id'], $organization);
        $vars = $this->parseSiteEnv($site)['variables'];
        $showValues = (bool) ($data['show_values'] ?? false);

        return Response::json([
            'site_id' => $site->id,
            'env_cache_origin' => $site->env_cache_origin,
            'env_synced_at' => $site->env_synced_at?->toIso8601String(),
            'count' => count($vars),
            'data' => $showValues
                ? $vars
                : array_keys($vars),
        ]);
    }
}
