<?php

namespace App\Support\Servers;

class ClassifyProvisionFailure
{
    /**
     * @param  list<array{key:string,label:string,status:string,detail:?string}>  $verificationChecks
     * @return array{code:string,label:string,detail:string}
     */
    public static function classify(
        ?string $failedStepLabel,
        ?string $taskOutputTail,
        array $verificationChecks,
        ?string $rollbackStatus,
    ): array {
        $step = strtolower(trim((string) $failedStepLabel));
        $output = strtolower(trim((string) $taskOutputTail));

        if ($rollbackStatus === 'repair_required') {
            return [
                'code' => 'rollback_partial',
                'label' => 'Rollback needs repair',
                'detail' => 'Dply could not safely restore everything automatically, so this server likely needs manual inspection before reuse.',
            ];
        }

        if ($thisHasVerificationFailure = collect($verificationChecks)->contains(fn (array $check): bool => $check['status'] !== 'ok')) {
            return [
                'code' => 'verification_failure',
                'label' => 'Verification failure',
                'detail' => 'Provisioning reached verification, but one or more service checks did not pass.',
            ];
        }

        if (str_contains($step, 'testing server connection') || str_contains($output, 'permission denied') || str_contains($output, 'connection timed out') || str_contains($output, 'connection refused')) {
            return [
                'code' => 'provider_or_connectivity',
                'label' => 'Connectivity issue',
                'detail' => 'Provisioning could not reliably reach or authenticate with the server during setup.',
            ];
        }

        if (str_contains($step, 'installing') && (str_contains($output, 'apt-get') || str_contains($output, 'package') || str_contains($output, 'repository'))) {
            return [
                'code' => 'package_install',
                'label' => 'Package install failure',
                'detail' => 'Provisioning failed while installing or updating required system packages.',
            ];
        }

        if (str_contains($output, 'nginx -t') || str_contains($output, 'caddy validate') || str_contains($output, 'haproxy -c') || str_contains($output, 'config test')) {
            return [
                'code' => 'config_validation',
                'label' => 'Config validation failure',
                'detail' => 'A generated service configuration did not pass validation after it was written.',
            ];
        }

        if (str_contains($output, 'systemctl') || str_contains($output, 'inactive') || str_contains($output, 'failed to start')) {
            return [
                'code' => 'service_startup',
                'label' => 'Service startup failure',
                'detail' => 'Provisioning wrote configuration, but a required service did not start cleanly afterward.',
            ];
        }

        return [
            'code' => 'unknown',
            'label' => 'Unknown failure',
            'detail' => 'Provisioning failed, but Dply could not confidently classify the root cause from the available output.',
        ];
    }
}
