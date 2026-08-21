<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Models\CloudDatabase;
use App\Modules\Cloud\Console\Concerns\ResolvesManagedDatabase;
use App\Modules\Database\Backends\DatabaseBackend;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\ResizeManagedDatabaseJob;
use Illuminate\Console\Command;
use Throwable;

/**
 * Move a managed database to a different plan.
 *
 *   dply:cloud:db:scale <database> <size> [--force]
 *
 * The provider migrates the data to new hardware and the cluster is
 * unreachable while it does — hence the confirmation. Attached apps should be
 * in maintenance mode first if a dropped write would matter.
 */
class CloudDatabaseScaleCommand extends Command
{
    use ResolvesManagedDatabase;

    protected $signature = 'dply:cloud:db:scale
        {database : Managed database ID or name}
        {size : Portable tier (small, medium, large) or a provider size slug}
        {--force : Skip the confirmation}';

    protected $description = 'Resize a managed database cluster in place.';

    public function handle(DatabaseRouter $router): int
    {
        $needle = (string) $this->argument('database');
        $database = $this->resolveManagedDatabase($needle);
        if ($database === null) {
            $this->error("Managed database not found: {$needle}");

            return self::FAILURE;
        }

        try {
            $supported = $router->backendFor($database)->supports(DatabaseBackend::CAP_RESIZE);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $supported) {
            $this->error("The {$database->backend} backend cannot resize a cluster in place.");

            return self::FAILURE;
        }

        $size = CloudDatabase::resolveSizeSlug((string) $this->argument('size'));
        $current = $database->backendSizeSlug();
        if ($size === $current) {
            $this->line("<fg=gray>Already on {$current}.</>");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Resize \"{$database->name}\" from {$current} to {$size}? The cluster goes offline during the move.")) {
            return self::SUCCESS;
        }

        $meta = $database->meta;
        $meta['resizing_to'] = $size;
        unset($meta['error'], $meta['error_at']);
        $database->forceFill(['meta' => $meta])->save();

        ResizeManagedDatabaseJob::dispatch((string) $database->id, null, $size);

        $this->info(sprintf('Resize queued: "%s" %s → %s.', $database->name, $current, $size));

        return self::SUCCESS;
    }
}
