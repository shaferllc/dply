<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;

final class DeployPipelineStepDuplicate
{
    /**
     * Find an existing step on the pipeline that matches the proposed command.
     */
    public static function find(
        SiteDeployPipeline $pipeline,
        string $stepType,
        ?string $customCommand = null,
        ?string $exceptStepId = null,
    ): ?SiteDeployStep {
        $normalizedCommand = self::normalizeCommand($customCommand);

        return $pipeline->steps()
            ->where('step_type', $stepType)
            ->when($exceptStepId !== null, fn ($query) => $query->whereKeyNot($exceptStepId))
            ->orderBy('sort_order')
            ->get()
            ->first(fn (SiteDeployStep $step) => self::matches($stepType, $normalizedCommand, $step));
    }

    public static function exists(
        SiteDeployPipeline $pipeline,
        string $stepType,
        ?string $customCommand = null,
        ?string $exceptStepId = null,
    ): bool {
        return self::find($pipeline, $stepType, $customCommand, $exceptStepId) !== null;
    }

    private static function matches(
        string $stepType,
        ?string $normalizedCommand,
        SiteDeployStep $existing,
    ): bool {
        if (in_array($stepType, [SiteDeployStep::TYPE_NPM_RUN, SiteDeployStep::TYPE_CUSTOM], true)) {
            return self::normalizeCommand($existing->custom_command) === $normalizedCommand;
        }

        return true;
    }

    private static function normalizeCommand(?string $command): ?string
    {
        if ($command === null) {
            return null;
        }

        $trimmed = trim($command);

        return $trimmed === '' ? null : $trimmed;
    }
}
