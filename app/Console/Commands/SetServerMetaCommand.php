<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Set or unset a key in a server's meta JSON column.
 *
 *   dply:server:meta-set <server> <key>=<value>
 *   dply:server:meta-set <server> <key>= --unset
 *   dply:server:meta-set <server> runtime_defaults.node=22.1.0
 *
 * Supports dot notation for nested paths via Laravel's data_set
 * helper. Values are stored as strings unless they parse as JSON
 * literals (true/false/null/numbers/objects/arrays) — pass --raw
 * to disable that auto-parsing and store the literal string.
 *
 * Reserved keys that the rest of the platform manages (e.g.
 * webserver, php_version) are still writable here intentionally —
 * this is the escape hatch for emergencies. Caller is responsible
 * for not breaking things.
 *
 * --dry-run reports the proposed mutation without writing.
 */
class SetServerMetaCommand extends Command
{
    protected $signature = 'dply:server:meta-set
        {server : Server ID, name, or IP}
        {assignment : key=value (use empty value with --unset to remove)}
        {--unset : Remove the key instead of setting it}
        {--raw : Store the value as a literal string (skip JSON-literal auto-parse)}
        {--dry-run : Report the proposed change without writing}
        {--json : Output as JSON}';

    protected $description = 'Set or unset a key (dot-notated) in a server\'s meta JSON column.';

    public function handle(): int
    {
        $needle = (string) $this->argument('server');
        $server = $this->resolveServer($needle);
        if ($server === null) {
            $this->error("Server not found: {$needle}");

            return self::FAILURE;
        }

        $assignment = (string) $this->argument('assignment');
        $eq = strpos($assignment, '=');
        if ($eq === false) {
            $this->error('Assignment must be key=value (or key= with --unset).');

            return self::FAILURE;
        }
        $key = trim(substr($assignment, 0, $eq));
        $rawValue = substr($assignment, $eq + 1);

        if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $key)) {
            $this->error('Key must match /^[A-Za-z_][A-Za-z0-9_.-]*$/.');

            return self::FAILURE;
        }

        $unset = (bool) $this->option('unset');
        $meta = is_array($server->meta) ? $server->meta : [];
        $previous = data_get($meta, $key);

        if ($unset) {
            Arr::forget($meta, $key);
            $newValue = null;
        } else {
            $newValue = (bool) $this->option('raw') ? $rawValue : $this->autoParse($rawValue);
            data_set($meta, $key, $newValue);
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun) {
            $server->meta = $meta;
            $server->save();
        }

        $payload = [
            'server_id' => $server->id,
            'server_name' => $server->name,
            'key' => $key,
            'unset' => $unset,
            'dry_run' => $dryRun,
            'previous' => $previous,
            'new_value' => $newValue,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would' : 'Did';
        $action = $unset ? 'remove' : 'set';
        $this->info(sprintf('%s %s meta.%s on %s.', $verb, $action, $key, $server->name));
        if (! $unset) {
            $this->line(sprintf('  previous: %s', $this->display($previous)));
            $this->line(sprintf('  new:      %s', $this->display($newValue)));
        }

        return self::SUCCESS;
    }

    /**
     * Auto-parse JSON literals (true/false/null/int/float/JSON
     * arrays/objects). Anything else is stored as a string.
     */
    private function autoParse(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (in_array(strtolower($trimmed), ['true', 'false', 'null'], true)) {
            return match (strtolower($trimmed)) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        if (preg_match('/^-?\d+$/', $trimmed)) {
            return (int) $trimmed;
        }
        if (preg_match('/^-?\d*\.\d+$/', $trimmed)) {
            return (float) $trimmed;
        }
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private function display(mixed $v): string
    {
        if ($v === null) {
            return '<fg=gray>null</>';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return json_encode($v) ?: '[?]';
        }

        return (string) $v;
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
