<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Server;
use App\Notifications\ServerRemovalScheduledNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

trait ManagesServerRemovalForm
{
    public string $deletionReason = '';

    public string $deletePhraseControl = '';

    public bool $deleteAckCloud = false;

    public bool $deleteAckSites = false;

    public string $currentPassword = '';

    protected function resetServerRemovalFormFields(): void
    {
        $this->deletionReason = '';
        $this->deletePhraseControl = '';
        $this->deleteAckCloud = false;
        $this->deleteAckSites = false;
        $this->currentPassword = '';
    }

    public function applyRemovalDatePreset(string $preset): void
    {
        $this->scheduledRemovalDate = match ($preset) {
            'tomorrow' => now()->addDay()->toDateString(),
            'week' => now()->addDays(7)->toDateString(),
            'month' => now()->addDays(30)->toDateString(),
            default => $this->scheduledRemovalDate,
        };
    }

    /**
     * @param  array{
     *   sites: int,
     *   databases: int,
     *   cron_jobs: int,
     *   supervisor_programs: int,
     *   firewall_rules: int,
     *   authorized_keys: int,
     *   recipes: int,
     *   running_deployments: int,
     *   provider_label: string,
     *   provider_value: string,
     *   will_destroy_cloud: bool,
     *   organization_name: ?string,
     * }  $summary
     * @return array<string, mixed>
     */
    protected function immediateServerRemovalRules(array $summary): array
    {
        $rules = [
            'deletePhraseControl' => ['required', 'string', Rule::in(['DELETE'])],
            'currentPassword' => ['required', 'current_password'],
        ];
        if ($summary['will_destroy_cloud']) {
            $rules['deleteAckCloud'] = ['accepted'];
        }
        if ($summary['sites'] > 0) {
            $rules['deleteAckSites'] = ['accepted'];
        }

        return $rules;
    }

    protected function notifyOrgAdminsOfScheduledRemoval(Server $server, Carbon $at, ?string $reason): void
    {
        if (! config('dply.server_deletion_notify_org_admins', true)) {
            return;
        }

        $org = $server->organization;
        if (! $org) {
            return;
        }

        $users = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
        if ($users->isEmpty()) {
            return;
        }

        $actor = auth()->user();
        $display = $at->copy()->timezone((string) config('app.timezone'))->toFormattedDateString();

        Notification::send(
            $users,
            new ServerRemovalScheduledNotification(
                $server,
                $display,
                $reason,
                $actor?->name ?? $actor?->email ?? '?',
            )
        );
    }
}
