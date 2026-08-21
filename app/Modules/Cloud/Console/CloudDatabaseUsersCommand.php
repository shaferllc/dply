<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Console\Concerns\ResolvesManagedDatabase;
use App\Modules\Database\Services\ManagedDatabaseUsers;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read and manage logins on a managed cluster.
 *
 *   dply:cloud:db:users <database> [--json]
 *   dply:cloud:db:users <database> --create=reporting
 *   dply:cloud:db:users <database> --rotate=reporting
 *   dply:cloud:db:users <database> --delete=reporting
 *
 * Creating and rotating print a password the provider will never return again.
 * dply grants no table privileges — do that with SQL once connected.
 */
class CloudDatabaseUsersCommand extends Command
{
    use ResolvesManagedDatabase;

    protected $signature = 'dply:cloud:db:users
        {database : Managed database ID or name}
        {--create= : Create a user with this name}
        {--rotate= : Rotate this user\'s password}
        {--delete= : Delete this user}
        {--json : Output the listing as JSON}';

    protected $description = 'List, create, rotate, or delete users on a managed database.';

    public function handle(ManagedDatabaseUsers $users): int
    {
        $needle = (string) $this->argument('database');
        $database = $this->resolveManagedDatabase($needle);
        if ($database === null) {
            $this->error("Managed database not found: {$needle}");

            return self::FAILURE;
        }

        if (! $users->supports($database)) {
            $this->error("The {$database->backend} backend does not expose a user API to dply.");

            return self::FAILURE;
        }

        try {
            $create = trim((string) $this->option('create'));
            if ($create !== '') {
                $created = $users->create($database, $create);
                $this->info("Created user \"{$created['name']}\".");
                $this->line("<fg=yellow>Password (shown once):</> {$created['password']}");

                return self::SUCCESS;
            }

            $rotate = trim((string) $this->option('rotate'));
            if ($rotate !== '') {
                $password = $users->rotatePassword($database, $rotate);
                $this->info("Rotated the password for \"{$rotate}\".");
                $this->line("<fg=yellow>Password (shown once):</> {$password}");
                if ($users->isClusterAdmin($database, $rotate)) {
                    $this->warn('That is the admin login — attached apps keep the old password until you re-attach them.');
                }

                return self::SUCCESS;
            }

            $delete = trim((string) $this->option('delete'));
            if ($delete !== '') {
                $users->delete($database, $delete);
                $this->info("Deleted user \"{$delete}\".");

                return self::SUCCESS;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $users->list($database);

        if ($this->option('json')) {
            $this->line(json_encode(['total' => count($rows), 'users' => $rows], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('<fg=gray>No users reported by the provider.</>');

            return self::SUCCESS;
        }

        $this->table(
            ['user', 'role', ''],
            array_map(fn (array $u): array => [
                $u['name'],
                $u['role'],
                $users->isClusterAdmin($database, $u['name']) ? 'admin' : '',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
