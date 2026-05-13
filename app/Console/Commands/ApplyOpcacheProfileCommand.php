<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplyServerOpcacheProfileJob;
use App\Models\Server;
use App\Models\ServerPhpOpcacheProfile;
use Illuminate\Console\Command;

/**
 * Create or update an OPcache profile for a (server, php_version) and ship
 * the rendered opcache.ini to the host. v1 CLI surface; the v2 server
 * workspace tab will drive the same job.
 */
class ApplyOpcacheProfileCommand extends Command
{
    protected $signature = 'dply:cache:opcache
        {server : Server ID or name}
        {php_version : PHP version, e.g. 8.3}
        {--memory= : opcache.memory_consumption (MB)}
        {--strings= : opcache.interned_strings_buffer (MB)}
        {--files= : opcache.max_accelerated_files}
        {--no-validate-timestamps : Disable opcache.validate_timestamps}
        {--revalidate-freq= : opcache.revalidate_freq (seconds)}
        {--jit=off : off|tracing|function}
        {--jit-buffer= : opcache.jit_buffer_size (MB)}
        {--disable : Set opcache.enable=0}';

    protected $description = 'Create / update / apply a PHP OPcache profile for a server.';

    public function handle(): int
    {
        $serverArg = (string) $this->argument('server');
        $server = Server::query()
            ->where('id', $serverArg)
            ->orWhere('name', $serverArg)
            ->first();
        if ($server === null) {
            $this->error("Server not found: {$serverArg}");

            return self::FAILURE;
        }

        $phpVersion = trim((string) $this->argument('php_version'));
        if (! preg_match('/^\d+\.\d+$/', $phpVersion)) {
            $this->error("PHP version must look like 8.3 (got {$phpVersion}).");

            return self::FAILURE;
        }

        $profile = ServerPhpOpcacheProfile::query()->firstOrNew([
            'server_id' => $server->id,
            'php_version' => $phpVersion,
        ]);

        if (! $profile->exists) {
            $profile->fill(ServerPhpOpcacheProfile::defaults());
        }

        if ($this->option('disable')) {
            $profile->enabled = false;
        } else {
            $profile->enabled = true;
        }
        if ($v = $this->option('memory')) {
            $profile->memory_consumption_mb = (int) $v;
        }
        if ($v = $this->option('strings')) {
            $profile->interned_strings_buffer_mb = (int) $v;
        }
        if ($v = $this->option('files')) {
            $profile->max_accelerated_files = (int) $v;
        }
        if ($this->option('no-validate-timestamps')) {
            $profile->validate_timestamps = false;
        }
        if ($v = $this->option('revalidate-freq')) {
            $profile->revalidate_freq = (int) $v;
        }
        $jit = (string) $this->option('jit');
        if (in_array($jit, ServerPhpOpcacheProfile::JIT_MODES, true)) {
            $profile->jit = $jit;
        }
        if ($v = $this->option('jit-buffer')) {
            $profile->jit_buffer_size_mb = (int) $v;
        }

        $profile->status = ServerPhpOpcacheProfile::STATUS_PENDING;
        $profile->save();

        ApplyServerOpcacheProfileJob::dispatch($profile->id);
        $this->info("Queued OPcache apply for PHP {$phpVersion} on {$server->name} (profile {$profile->id}).");

        return self::SUCCESS;
    }
}
