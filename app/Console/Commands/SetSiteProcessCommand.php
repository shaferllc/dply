<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Console\Command;

/**
 * Upsert a SiteProcess from the terminal.
 *
 *   dply:site:process-set <site> <name>
 *     [--type=worker]   [--command="..."]
 *     [--scale=2]       [--active=true|false]
 *     [--working-dir=]  [--user=]
 *     [--dry-run]       [--json]
 *
 * Identified by (site_id, name). Creates the row if missing and
 * updates in place if present. Type defaults to "worker" on create
 * and is left alone on update unless --type is passed.
 *
 * Validates --type against SiteProcess::TYPE_* constants. Validates
 * --scale is a non-negative int. --active accepts true/false/1/0.
 *
 * To remove a process, use dply:site:process-remove.
 */
class SetSiteProcessCommand extends Command
{
    protected $signature = 'dply:site:process-set
        {site : Site ID, slug, or name}
        {name : Process name (e.g. web, queue, scheduler)}
        {--type= : Process type (web, worker, scheduler, custom)}
        {--command= : Shell command the supervisor runs}
        {--scale= : Number of replicas (>= 0)}
        {--active= : true|false — whether the process should run}
        {--working-dir= : Working directory for the process}
        {--user= : System user to run the process as}
        {--dry-run : Report the proposed change without writing}
        {--json : Output as JSON}';

    protected $description = 'Create or update a site process row. Identified by (site, name).';

    private const ALLOWED_TYPES = [
        SiteProcess::TYPE_WEB,
        SiteProcess::TYPE_WORKER,
        SiteProcess::TYPE_SCHEDULER,
        SiteProcess::TYPE_CUSTOM,
    ];

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('Process name cannot be empty.');

            return self::FAILURE;
        }

        $changes = [];
        if ($this->option('type') !== null) {
            $type = (string) $this->option('type');
            if (! in_array($type, self::ALLOWED_TYPES, true)) {
                $this->error(sprintf(
                    'Invalid type "%s". Allowed: %s',
                    $type,
                    implode(', ', self::ALLOWED_TYPES),
                ));

                return self::FAILURE;
            }
            $changes['type'] = $type;
        }
        if ($this->option('command') !== null) {
            $changes['command'] = (string) $this->option('command');
        }
        if ($this->option('scale') !== null) {
            $scale = $this->option('scale');
            if (! ctype_digit((string) $scale) || (int) $scale < 0) {
                $this->error("Invalid scale: {$scale} (must be >= 0).");

                return self::FAILURE;
            }
            $changes['scale'] = (int) $scale;
        }
        if ($this->option('active') !== null) {
            $active = $this->parseBool((string) $this->option('active'));
            if ($active === null) {
                $this->error('--active must be true|false|1|0.');

                return self::FAILURE;
            }
            $changes['is_active'] = $active;
        }
        if ($this->option('working-dir') !== null) {
            $changes['working_directory'] = (string) $this->option('working-dir') ?: null;
        }
        if ($this->option('user') !== null) {
            $changes['user'] = (string) $this->option('user') ?: null;
        }

        $existing = $site->processes()->where('name', $name)->first();
        $action = $existing === null ? 'create' : 'update';

        if ($action === 'create') {
            // Create requires sane defaults so the row is meaningful.
            $changes['type'] = $changes['type'] ?? SiteProcess::TYPE_WORKER;
            $changes['command'] = $changes['command'] ?? '';
            $changes['scale'] = $changes['scale'] ?? 1;
            $changes['is_active'] = $changes['is_active'] ?? true;
        } elseif ($changes === []) {
            $this->error('No changes requested.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $process = $existing;
        if (! $dryRun) {
            if ($action === 'create') {
                $process = $site->processes()->create(array_merge(['name' => $name], $changes));
            } else {
                $existing->fill($changes)->save();
                $process = $existing->fresh();
            }
        }

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'process_name' => $name,
            'action' => $action,
            'dry_run' => $dryRun,
            'changes' => $changes,
            'process' => $process ? [
                'id' => $process->id,
                'type' => $process->type,
                'name' => $process->name,
                'command' => $process->command,
                'scale' => $process->scale,
                'is_active' => (bool) $process->is_active,
            ] : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would '.$action : ucfirst($action).'d';
        $this->info(sprintf('%s process "%s" on %s.', $verb, $name, $site->name));
        foreach ($changes as $col => $val) {
            $this->line(sprintf('  %-18s %s', $col, $this->display($val)));
        }

        return self::SUCCESS;
    }

    private function parseBool(string $value): ?bool
    {
        $v = strtolower(trim($value));

        return match ($v) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => null,
        };
    }

    private function display(mixed $v): string
    {
        if ($v === null) {
            return '<fg=gray>null</>';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return (string) $v;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
