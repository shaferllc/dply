<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Database;

use App\Jobs\CreateSiteDatabaseJob;
use App\Mcp\Exceptions\DplyMcpException;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Services\Servers\DatabaseEngineReadinessGuard;
use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class CreateSiteDatabase extends AbstractDplyTool
{
    protected string $name = 'create_site_database';

    protected string $description = <<<'TXT'
        Create a database for a site on its server. Generates a username/password
        when not given, provisions the database over SSH, and (by default) writes
        the DB_* connection vars into the site's .env and pushes them. The engine
        must be installed on the server. Returns an `operation_id` to poll with
        `get_operation_status`, plus the new database id.
        TXT;

    protected string $ability = 'database.write';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()
                ->description('The site id (or slug).')
                ->required(),
            'name' => $schema->string()
                ->description('Database name (letters, numbers, underscores; max 64).')
                ->required(),
            'engine' => $schema->string()
                ->description('Engine: mysql, mariadb, postgres, mongodb, clickhouse, or sqlite. Defaults to the site\'s configured engine.')
                ->enum(DatabaseWorkspaceEngines::ENGINE_TABS),
            'description' => $schema->string()
                ->description('Optional human description.'),
            'write_env' => $schema->boolean()
                ->description('Write DB_* connection vars into the site\'s .env. Defaults to true.'),
            'push_env' => $schema->boolean()
                ->description('Push the .env to the server after writing. Defaults to true (only applies when write_env is true).'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/'],
            'engine' => ['nullable', 'string', Rule::in(DatabaseWorkspaceEngines::ENGINE_TABS)],
            'description' => ['nullable', 'string', 'max:2000'],
            'write_env' => ['nullable', 'boolean'],
            'push_env' => ['nullable', 'boolean'],
        ]);

        $site = $this->resolveSite($data['site_id'], $organization);
        $this->assertVmSite($site, 'Creating a database');
        $server = $site->server;

        $engine = $data['engine'] ?? (string) $site->database_engine;
        if ($engine === '') {
            throw new DplyMcpException('Specify an engine (the site has no default database engine configured).');
        }

        $readiness = app(DatabaseEngineReadinessGuard::class)->check($server, $engine);
        if (! $readiness['ok']) {
            throw new DplyMcpException((string) $readiness['reason']);
        }

        $nameTaken = ServerDatabase::query()
            ->where('server_id', $server->id)
            ->where('name', $data['name'])
            ->exists();
        if ($nameTaken) {
            throw new DplyMcpException("A database named \"{$data['name']}\" is already tracked on this server.");
        }

        $writeEnv = (bool) ($data['write_env'] ?? true);
        $pushEnv = (bool) ($data['push_env'] ?? true);

        $db = ServerDatabase::query()->create($this->rowAttributes($site, $engine, $data['name'], $data['description'] ?? null));

        $run = ConsoleAction::query()->create([
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'kind' => 'site_db_create',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => 'Create '.DatabaseWorkspaceEngines::label($engine).' database '.$db->name,
            'user_id' => $this->token()->user_id,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        CreateSiteDatabaseJob::dispatch(
            $db->id,
            $site->id,
            $writeEnv,
            $writeEnv && $pushEnv,
            $this->token()->user_id,
            (string) $run->id,
        );

        return Response::json([
            'status' => 'queued',
            'message' => "Creating {$engine} database \"{$db->name}\".",
            'site_id' => $site->id,
            'database_id' => $db->id,
            'operation_id' => $run->id,
            'operation_kind' => 'site_db_create',
            'poll_with' => 'get_operation_status',
        ]);
    }

    /**
     * Build the ServerDatabase row, mirroring the Livewire create flow's
     * credential + host resolution (incl. the sqlite special case).
     *
     * @return array<string, mixed>
     */
    private function rowAttributes(Site $site, string $engine, string $name, ?string $description): array
    {
        $isSqlite = DatabaseWorkspaceEngines::family($engine) === 'sqlite';

        if ($isSqlite) {
            $root = rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');

            return [
                'server_id' => $site->server_id,
                'site_id' => $site->id,
                'name' => $name,
                'engine' => $engine,
                'username' => '',
                'password' => '',
                'host' => $root.'/'.$site->server_id.'/'.$name.'.db',
                'description' => $description ?: null,
            ];
        }

        $base = Str::slug($name, '_') ?: 'db';
        $username = Str::limit($base, 28, '').'_'.Str::lower(Str::random(4));

        return [
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'name' => $name,
            'engine' => $engine,
            'username' => $username,
            'password' => ServerDatabase::generateConnectionSafePassword(),
            'host' => '127.0.0.1',
            'description' => $description ?: null,
        ];
    }
}
