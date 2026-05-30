<?php

declare(strict_types=1);

namespace App\Actions\DeployContract;

use App\Models\DeployContractRun;
use App\Models\Site;
use App\Models\User;
use App\Services\DeployContract\DeployContractState;
use InvalidArgumentException;

final class WaiveDeployContractRun
{
    public function handle(
        Site $parent,
        Site $preview,
        User $waivedBy,
        string $reason,
    ): DeployContractRun {
        if (! (bool) config('deploy_contract.allow_waivers', true)) {
            throw new InvalidArgumentException(__('Deploy contract waivers are disabled.'));
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException(__('A waiver reason is required.'));
        }

        $state = app(DeployContractState::class);
        $run = $state->latestRunForPreview($parent, $preview);

        if ($run === null) {
            throw new InvalidArgumentException(__('Run the deploy contract before recording a waiver.'));
        }

        if ($run->status !== DeployContractRun::STATUS_FAILED) {
            throw new InvalidArgumentException(__('Only a failed contract run can be waived.'));
        }

        if (! $state->runMatchesCurrentDeployment($run, $preview)) {
            throw new InvalidArgumentException(__('Re-run the contract on the current preview deployment before waiving.'));
        }

        $run->update([
            'status' => DeployContractRun::STATUS_WAIVED,
            'waiver_reason' => $reason,
            'waived_by_user_id' => $waivedBy->id,
            'waived_at' => now(),
        ]);

        return $run->fresh();
    }
}
