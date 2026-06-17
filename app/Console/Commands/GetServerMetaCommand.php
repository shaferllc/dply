<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Print a key from a server's meta JSON column.
 *
 *   dply:server:meta-get <server> <key>
 *   dply:server:meta-get <server> runtime_defaults.node
 *   dply:server:meta-get <server> webserver --json
 *
 * Supports dot notation via data_get for nested keys. Default
 * output is JSON-encoded so arrays and objects round-trip cleanly.
 * Pass no key to dump the entire meta blob.
 *
 * Exits 1 when the key is missing, so shell scripts can detect
 * absence via exit code:
 *
 *   if dply:server:meta-get prod-1 some_flag > /dev/null; then ...
 */
class GetServerMetaCommand extends Command
{
    protected $signature = 'dply:server:meta-get
        {server : Server ID, name, or IP}
        {key? : Dot-notated meta key (omit to dump everything)}
        {--json : Wrap output in a JSON envelope (server_id + key + value)}';

    protected $description = 'Print a key from a server\'s meta JSON column.';

    public function handle(): int
    {
        $needle = (string) $this->argument('server');
        $server = $this->resolveServer($needle);
        if ($server === null) {
            $this->error("Server not found: {$needle}");

            return self::FAILURE;
        }

        $meta = $server->meta;
        $key = $this->argument('key');

        if ($key === null) {
            $value = $meta;
        } else {
            $exists = data_get($meta, $key, '__missing__') !== '__missing__';
            if (! $exists) {
                if ((bool) $this->option('json')) {
                    $this->line(json_encode([
                        'server_id' => $server->id,
                        'key' => $key,
                        'value' => null,
                        'present' => false,
                    ], JSON_PRETTY_PRINT));
                }

                return self::FAILURE;
            }
            $value = data_get($meta, $key);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'server_id' => $server->id,
                'key' => $key,
                'value' => $value,
                'present' => true,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line($this->renderValue($value));

        return self::SUCCESS;
    }

    private function renderValue(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }

        return (string) json_encode($v, JSON_UNESCAPED_SLASHES);
    }

    private function resolveServer(string $needle): ?Server
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Server::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->orWhere('ip_address', $needle)
            ->first();
    }
}
