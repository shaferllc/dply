<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerCronJob;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCronBundles
{
    /**
     * Inserts panel rows from a `config('cron_workspace.bundled_jobs')` bundle. Skips entries
     * that already match (same command + expression + user); commands often include placeholders
     * the user must edit before syncing the crontab to the server.
     */
    public function applyCronBundle(string $key): void
    {
        $this->authorize('update', $this->server);

        $bundle = config('cron_workspace.bundled_jobs.'.$key);
        if (! is_array($bundle) || empty($bundle['entries'])) {
            $this->toastError(__('Unknown cron bundle.'));

            return;
        }

        $created = 0;
        $skipped = 0;
        $createdLines = [];
        $defaultSshUser = trim((string) $this->server->ssh_user) ?: 'root';

        foreach ($bundle['entries'] as $entry) {
            $command = trim((string) ($entry['command'] ?? ''));
            $expression = trim((string) ($entry['cron_expression'] ?? ''));
            if ($command === '' || $expression === '') {
                continue;
            }
            $user = trim((string) ($entry['user'] ?? '')) ?: $defaultSshUser;

            $duplicate = ServerCronJob::query()
                ->where('server_id', $this->server->id)
                ->where('command', $command)
                ->where('cron_expression', $expression)
                ->where('user', $user)
                ->exists();

            if ($duplicate) {
                $skipped++;

                continue;
            }

            ServerCronJob::query()->create([
                'server_id' => $this->server->id,
                'cron_expression' => $expression,
                'command' => $command,
                'user' => $user,
                'enabled' => true,
                'is_synced' => false,
                'description' => $entry['description'] ?? null,
                'overlap_policy' => $entry['overlap_policy'] ?? ServerCronJob::OVERLAP_ALLOW,
                'schedule_timezone' => config('app.timezone'),
            ]);
            $created++;
            $createdLines[] = sprintf('  + %s   %s', $expression, Str::limit($command, 80));
        }

        if ($created === 0) {
            $this->toastWarning(__('All entries from ":label" already exist on this server.', ['label' => $bundle['label'] ?? $key]));

            return;
        }

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.cron.bundle_applied',
            $this->server,
            null,
            [
                'bundle_key' => $key,
                'bundle_label' => $bundle['label'] ?? $key,
                'created' => $created,
                'skipped' => $skipped,
            ],
        );

        $this->emitPanelEvent(
            __('Bundle ":label" added — sync to install on server', ['label' => $bundle['label'] ?? $key]),
            array_values(array_filter([
                sprintf('> Added %d cron %s from bundle "%s" to the panel.', $created, $created === 1 ? 'job' : 'jobs', $bundle['label'] ?? $key),
                $skipped > 0 ? sprintf('  (%d duplicate %s skipped)', $skipped, $skipped === 1 ? 'entry' : 'entries') : null,
                ...$createdLines,
                '> Review commands (paths, domains, credentials), then click "Sync crontab" to install the Dply-managed block.',
            ])),
        );

        $this->toastSuccess(__('Added :n cron :word from ":label". Review and sync.', [
            'n' => $created,
            'word' => $created === 1 ? 'job' : 'jobs',
            'label' => $bundle['label'] ?? $key,
        ]));
    }

    /**
     * Returns the configured one-click bundles, augmented with `entry_count` and `applied`
     * (true when every entry already exists on this server). The view renders an "Added"
     * indicator when applied.
     *
     * @return array<string, array{label: string, description: string, entry_count: int, applied: bool}>
     */
    protected function bundledCronJobsForView(): array
    {
        $bundles = config('cron_workspace.bundled_jobs', []);
        if (! is_array($bundles) || $bundles === []) {
            return [];
        }

        $defaultSshUser = trim((string) $this->server->ssh_user) ?: 'root';
        $existing = $this->server->cronJobs
            ->map(fn (ServerCronJob $j) => trim((string) $j->cron_expression).'|'.trim((string) $j->command).'|'.trim((string) $j->user))
            ->all();
        $existingSet = array_flip($existing);

        $out = [];
        foreach ($bundles as $key => $bundle) {
            $entries = $bundle['entries'] ?? [];
            if (! is_array($entries) || $entries === []) {
                continue;
            }
            $allApplied = true;
            foreach ($entries as $entry) {
                $cmd = trim((string) ($entry['command'] ?? ''));
                $expr = trim((string) ($entry['cron_expression'] ?? ''));
                if ($cmd === '' || $expr === '') {
                    $allApplied = false;

                    continue;
                }
                $user = trim((string) ($entry['user'] ?? '')) ?: $defaultSshUser;
                if (! isset($existingSet[$expr.'|'.$cmd.'|'.$user])) {
                    $allApplied = false;
                    break;
                }
            }
            $out[$key] = [
                'label' => (string) ($bundle['label'] ?? $key),
                'description' => (string) ($bundle['description'] ?? ''),
                'entry_count' => count($entries),
                'applied' => $allApplied,
            ];
        }

        return $out;
    }
}
