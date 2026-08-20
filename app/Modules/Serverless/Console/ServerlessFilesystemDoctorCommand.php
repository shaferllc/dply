<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Console\Command;

/**
 * Diagnose filesystem / session / logging / queue combos that are unsafe on a function.
 *
 *   dply:serverless:filesystem-doctor <site> [--fix] [--json]
 *
 * A function's disk is ephemeral. `FILESYSTEM_DISK=local`, `SESSION_DRIVER=file`,
 * `LOG_CHANNEL=stack|single|daily` (writes `storage/logs`), and
 * `QUEUE_CONNECTION=sync` look like they work and then silently lose data
 * (or crash on a read-only filesystem). `--fix` rewrites session to `cookie`
 * and file log channels to `stderr`; disk and queue still need an operator
 * choice (object storage / dply Queue).
 */
class ServerlessFilesystemDoctorCommand extends Command
{
    protected $signature = 'dply:serverless:filesystem-doctor
        {site : Site ID, slug, or name}
        {--fix : Apply safe session and logging defaults to the managed environment}
        {--json : Output the diagnostic as JSON}';

    protected $description = 'Diagnostic: local disk, file sessions, file logs, and sync queue on a serverless function.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);

        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if (! $site->usesFunctionsRuntime()) {
            $this->error('Not a serverless function site: '.$site->slug);

            return self::FAILURE;
        }

        $report = $this->compile($site);
        $fix = null;

        if ($this->option('fix')) {
            $fix = $this->fix($site, $report);
            $report = $this->compile($site->fresh() ?? $site);
            $report['fix'] = $fix;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['problems'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($site, $report);

        return $report['problems'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function compile(Site $site): array
    {
        $parsed = (new DotEnvFileParser)->parse((string) $site->env_file_content);
        $env = $parsed['variables'];

        $disk = strtolower(trim((string) ($env['FILESYSTEM_DISK'] ?? '')));
        $session = strtolower(trim((string) ($env['SESSION_DRIVER'] ?? '')));
        $queue = strtolower(trim((string) ($env['QUEUE_CONNECTION'] ?? '')));
        $logChannel = strtolower(trim((string) ($env['LOG_CHANNEL'] ?? '')));
        $logStack = strtolower(trim((string) ($env['LOG_STACK'] ?? 'single')));

        $problems = [];
        $notes = [];
        $fixable = [];

        if ($disk === '' || $disk === 'local' || $disk === 'public') {
            $problems[] = 'FILESYSTEM_DISK is '.($disk === '' ? 'unset (Laravel defaults to `local`)' : '`'.$disk.'`')
                .'. On a function that disk is ephemeral — uploads vanish when the container recycles. Attach object storage on Resources, set FILESYSTEM_DISK=s3, then redeploy.';
        }

        if (in_array($session, ['file', 'array'], true)) {
            $problems[] = 'SESSION_DRIVER is `'.$session.'`. File sessions live in `/tmp`; array sessions die with the request. Use `cookie` (the Functions default) or a shared store such as redis/database.';
            $fixable[] = 'session';
        } elseif ($session === '') {
            $notes[] = 'SESSION_DRIVER is unset — the injected handler defaults it to `cookie` at boot.';
        }

        $fileLogChannels = ['single', 'daily'];
        if (in_array($logChannel, $fileLogChannels, true)) {
            $problems[] = 'LOG_CHANNEL is `'.$logChannel.'`. That writes to storage/logs, which is not writable on a function. Use `stderr` (the Functions default) or a remote drain.';
            $fixable[] = 'logging';
        } elseif ($logChannel === 'stack') {
            $stackChannels = array_values(array_filter(array_map('trim', explode(',', $logStack))));
            $fileInStack = array_values(array_intersect($stackChannels === [] ? ['single'] : $stackChannels, $fileLogChannels));
            if ($fileInStack !== []) {
                $problems[] = 'LOG_CHANNEL=stack writes through `'.implode('/', $fileInStack).'` to storage/logs, which is not writable on a function. Use `stderr` (the Functions default) or a remote drain.';
                $fixable[] = 'logging';
            }
        } elseif ($logChannel === '') {
            $notes[] = 'LOG_CHANNEL is unset — the injected handler defaults it to `stderr` at boot.';
        }

        if ($queue === '' || $queue === 'sync') {
            $notes[] = 'QUEUE_CONNECTION is '.($queue === '' ? 'unset (the handler defaults it to `sync`)' : '`sync`')
                .'. Dispatched jobs run inline and nothing is ever queued. See `dply:serverless:queue-doctor` to wire a shared queue.';
        }

        return [
            'site' => ['id' => $site->id, 'slug' => $site->slug, 'name' => $site->name],
            'filesystem_disk' => $disk !== '' ? $disk : null,
            'session_driver' => $session !== '' ? $session : null,
            'log_channel' => $logChannel !== '' ? $logChannel : null,
            'queue_connection' => $queue !== '' ? $queue : null,
            'problems' => $problems,
            'notes' => $notes,
            'fixable' => $fixable,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function fix(Site $site, array $report): array
    {
        $applied = [];
        $fixable = $report['fixable'] ?? [];

        if ($fixable === []) {
            return ['applied' => [], 'message' => 'Nothing safe to auto-fix. Attach object storage / a shared queue, then redeploy.'];
        }

        $content = (string) $site->env_file_content;

        if (in_array('session', $fixable, true)) {
            $content = $this->upsertEnvKey($content, 'SESSION_DRIVER', 'cookie');
            $applied[] = 'SESSION_DRIVER=cookie';
        }

        if (in_array('logging', $fixable, true)) {
            $content = $this->upsertEnvKey($content, 'LOG_CHANNEL', 'stderr');
            $applied[] = 'LOG_CHANNEL=stderr';
        }

        $site->forceFill(['env_file_content' => $content])->save();

        return [
            'applied' => $applied,
            'message' => 'Updated the managed environment. Redeploy the function for this to take effect.',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(Site $site, array $report): void
    {
        $this->line('');
        $this->line('<options=bold>Filesystem doctor — '.$site->slug.'</>');
        $this->line('');
        $this->line('  FILESYSTEM_DISK     '.($report['filesystem_disk'] ?? '<fg=red>not set</>'));
        $this->line('  SESSION_DRIVER      '.($report['session_driver'] ?? '<fg=yellow>not set (cookie default)</>'));
        $this->line('  LOG_CHANNEL         '.($report['log_channel'] ?? '<fg=yellow>not set (stderr default)</>'));
        $this->line('  QUEUE_CONNECTION    '.($report['queue_connection'] ?? '<fg=yellow>not set (sync default)</>'));

        if (isset($report['fix'])) {
            $this->line('');
            $this->line('<options=bold>Fix</>');
            foreach ($report['fix']['applied'] ?? [] as $applied) {
                $this->line('  '.$applied);
            }
            $this->line('  '.(string) ($report['fix']['message'] ?? ''));
        }

        foreach ($report['notes'] as $note) {
            $this->line('');
            $this->line('  <fg=yellow>note</> '.$note);
        }

        foreach ($report['problems'] as $problem) {
            $this->line('');
            $this->line('  <fg=red>problem</> '.$problem);
        }

        $this->line('');

        if ($report['problems'] === []) {
            $this->info('No blocking problems found.');

            return;
        }

        $this->warn(count($report['problems']).' problem(s) found.');
    }

    private function upsertEnvKey(string $content, string $key, string $value): string
    {
        $lines = $content === '' ? [] : (preg_split('/\r\n|\r|\n/', $content) ?: []);
        $entry = $key.'='.$value;
        $replaced = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', (string) $line) === 1) {
                $lines[$index] = $entry;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $lines[] = $entry;
        }

        return implode("\n", $lines);
    }

    private function resolveSite(string $needle): ?Site
    {
        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
