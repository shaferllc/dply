<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Database;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\ServerDatabase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListSiteDatabases extends AbstractDplyTool
{
    protected string $name = 'list_site_databases';

    protected string $description = 'List databases for a site: those owned by the site plus unlinked databases on its server. Returns name, engine, username, host, and ownership.';

    protected string $ability = 'database.read';

    /**
     * @return array<string, JsonSchema>
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

        $databases = ServerDatabase::query()
            ->where('site_id', $site->id)
            ->orWhere(fn ($q) => $q->where('server_id', $site->server_id)->whereNull('site_id'))
            ->get(['id', 'name', 'engine', 'username', 'host', 'site_id', 'description']);

        return Response::json([
            'data' => $databases->map(fn (ServerDatabase $db) => [
                'id' => $db->id,
                'name' => $db->name,
                'engine' => $db->engine,
                'username' => $db->username,
                'host' => $db->host ?? '127.0.0.1',
                'site_owned' => $db->site_id === $site->id,
                'description' => $db->description,
            ])->all(),
        ]);
    }
}
