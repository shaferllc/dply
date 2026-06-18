<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerCrontabReader;
use App\Services\Servers\ServerPasswdUserLister;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCronInspection
{
    public string $inspect_crontab_user = '';

    public ?string $inspect_crontab_body = null;

    public ?int $inspect_crontab_exit_code = null;

    /**
     * Run-as field: merge /etc/passwd names (cached) with SSH user, root, and job run-as users.
     *
     * @return list<string>
     */
    protected function runAsUserDatalistChoices(): array
    {
        $local = $this->crontabInspectUserChoices();

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            return $local;
        }

        try {
            $remote = Cache::remember(
                'server_passwd_usernames:'.$this->server->id,
                now()->addMinutes(5),
                fn () => app(ServerPasswdUserLister::class)->listUsernames($this->server)
            );
        } catch (\Throwable) {
            $remote = [];
        }

        return collect($local)
            ->merge($remote)
            ->map(fn ($u) => trim((string) $u))
            ->filter(fn ($u) => $u !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $u))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function refreshRunAsUserChoices(): void
    {
        $this->authorize('update', $this->server);
        Cache::forget('server_passwd_usernames:'.$this->server->id);
        $this->toastSuccess(__('Reloaded user names from /etc/passwd on the server.'));
    }

    /**
     * Suggested usernames for the crontab inspector (SSH account, root, and run-as users from jobs).
     *
     * @return list<string>
     */
    protected function crontabInspectUserChoices(): array
    {
        $this->server->loadMissing('cronJobs');
        $ssh = trim((string) $this->server->ssh_user) ?: 'root';

        return collect([$ssh, 'root'])
            ->merge($this->server->cronJobs->pluck('user'))
            ->map(fn ($u) => trim((string) $u))
            ->filter(fn ($u) => $u !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $u))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function updatedInspectCrontabUser(): void
    {
        // Auto-reload the crontab when the operator picks a different user from
        // the dropdown — no separate Reload click needed.
        if (trim($this->inspect_crontab_user) !== '') {
            $this->loadInspectCrontab(app(ServerCrontabReader::class));
        }
    }

    public function loadInspectCrontab(ServerCrontabReader $reader): void
    {
        // Read-only (crontab -l) — mirrors the daemons/schedule read actions and
        // lets the Inspect tab auto-load for view-only users without a 403.
        $this->authorize('view', $this->server);
        $this->inspect_crontab_body = null;
        $this->inspect_crontab_exit_code = null;

        $this->validate([
            'inspect_crontab_user' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
        ], [
            'inspect_crontab_user.regex' => __('Use a valid Linux username.'),
        ]);

        try {
            $this->server->refresh();
            $result = $reader->readForUser($this->server->fresh(), trim($this->inspect_crontab_user));
            $this->inspect_crontab_body = $result['body'];
            $this->inspect_crontab_exit_code = $result['exit_code'];

            $noCrontabYet = $result['exit_code'] === 1
                && $result['body'] !== ''
                && str_contains(strtolower($result['body']), 'no crontab');

            if ($result['exit_code'] !== null && $result['exit_code'] !== 0 && ! $noCrontabYet) {
                $this->toastError(__('Could not read crontab (exit :code). Output is shown below.', ['code' => $result['exit_code']]));
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function syncCronJobs(ServerCronSynchronizer $synchronizer): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->server->refresh();

            // Pre-flight: refuse to ship a crontab the host will reject — we
            // surface invalid rows with edit links in the banner above the
            // table so the operator can fix them in place. Without this, a
            // bad expression aborts the whole DPLY MANAGED block install.
            $invalid = $synchronizer->invalidExpressions($this->server->cronJobs);
            if ($invalid !== []) {
                $this->emitPanelEvent(
                    __('Cannot sync — :count cron job(s) have invalid expressions.', ['count' => count($invalid)]),
                    array_merge(
                        ['> Pre-flight rejected the sync; fix the highlighted jobs and try again.'],
                        array_map(
                            static fn (array $row): string => sprintf(
                                '> #%s  expr=%s  cmd=%s',
                                substr($row['id'], -6),
                                $row['cron_expression'],
                                Str::limit($row['command'], 80),
                            ),
                            $invalid,
                        ),
                    ),
                    'failed',
                );
                $this->toastError(trans_choice(
                    '{1} :count cron job has an invalid expression — fix it before syncing.|[2,*] :count cron jobs have invalid expressions — fix them before syncing.',
                    count($invalid),
                    ['count' => count($invalid)],
                ));

                return;
            }

            $out = $synchronizer->sync($this->server);
            $ok = (bool) preg_match('/DPLY_CRON_EXIT:0\s*$/', $out);

            if (! $ok) {
                // The host rejected the crontab. Render the proposed body with
                // line numbers + a highlight for the offending line so the
                // operator can identify exactly what crontab(1) didn't like.
                $body = (string) $synchronizer->lastBody();
                $badLine = $synchronizer->lastBadLine();
                $badContent = $synchronizer->lastBadLineContent();

                $lines = $body === '' ? [] : (preg_split("/\r?\n/", $body) ?: []);
                $width = strlen((string) count($lines));
                $numbered = [];
                foreach ($lines as $i => $line) {
                    $n = $i + 1;
                    $marker = $badLine !== null && $n === $badLine ? '>>' : '  ';
                    $numbered[] = sprintf('%s %'.$width.'d │ %s', $marker, $n, $line);
                }

                $transcript = ['> Crontab rejected by host — output:'];
                $transcript = array_merge($transcript, $this->splitOutputForBanner($out));
                if ($badLine !== null) {
                    $transcript[] = '> ';
                    $transcript[] = sprintf('> Offending line %d: %s', $badLine, (string) $badContent);
                }
                if ($numbered !== []) {
                    $transcript[] = '> ';
                    $transcript[] = '> --- rendered crontab body (lines marked with ">>" are the rejected ones) ---';
                    $transcript = array_merge($transcript, $numbered);
                }

                $this->emitPanelEvent(
                    $badLine !== null
                        ? __('Crontab rejected — see line :line below.', ['line' => $badLine])
                        : __('Crontab rejected by host.'),
                    $transcript,
                    'failed',
                );
                $this->toastError(__('Crontab install failed — banner shows the rendered body and the rejected line.'));

                return;
            }

            audit_log(
                $this->server->organization,
                auth()->user(),
                'server.cron.synced',
                $this->server,
                null,
                ['result' => 'success'],
            );

            $this->emitPanelEvent(
                __('Crontab synced to server.'),
                array_merge(
                    ['> Wrote the Dply-managed crontab block via SSH.'],
                    $this->splitOutputForBanner($out),
                ),
                'completed',
            );
            $this->toastSuccess(__('Crontab sync finished — see the banner for the host output.'));
        } catch (\Throwable $e) {
            audit_log(
                $this->server->organization,
                auth()->user(),
                'server.cron.synced',
                $this->server,
                null,
                [
                    'result' => 'failed',
                    'error' => Str::limit($e->getMessage(), 500),
                ],
            );

            $this->emitPanelEvent(
                __('Crontab sync failed.'),
                [
                    '> Tried to write the Dply-managed crontab block via SSH.',
                    '> ERROR: '.Str::limit(trim($e->getMessage()), 800),
                ],
                'failed',
            );
            $this->toastError($e->getMessage());
        }
    }
}
