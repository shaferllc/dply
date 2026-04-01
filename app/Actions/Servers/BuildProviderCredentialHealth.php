<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Models\ProviderCredential;
use App\Services\AwsEc2Service;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\VultrService;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class BuildProviderCredentialHealth
{
    use AsObject;

    /**
     * @return array{
     *     status: 'ok'|'invalid'|'expired'|'under_scoped'|'rate_limited'|'misconfigured'|'unknown',
     *     severity: 'info'|'warning'|'error',
     *     label: string,
     *     detail: string,
     *     provider_message: ?string,
     *     checked_at: ?string
     * }
     */
    public function handle(string $type, ?ProviderCredential $credential): array
    {
        if (! $credential) {
            return $this->result(
                'misconfigured',
                'error',
                __('No provider credential selected'),
                __('Choose a provider account before dply can verify access.'),
                null,
                null,
            );
        }

        $cacheKey = sprintf('server-create:provider-health:%s:%s:%s', $type, $credential->id, md5(json_encode($credential->credentials ?? [])));

        $resolver = function () use ($type, $credential): array {
            try {
                $this->runHealthCheck($type, $credential);

                return $this->result(
                    'ok',
                    'info',
                    __('Credential verified'),
                    __('The selected provider account responded successfully and looks ready for provisioning.'),
                    null,
                    now()->toIso8601String(),
                );
            } catch (Throwable $e) {
                [$status, $severity, $label, $detail] = $this->classifyFailure($type, $e);

                return $this->result(
                    $status,
                    $severity,
                    $label,
                    $detail,
                    trim($e->getMessage()) !== '' ? $e->getMessage() : null,
                    now()->toIso8601String(),
                );
            }
        };

        if (app()->environment('testing')) {
            return $resolver();
        }

        return Cache::remember($cacheKey, now()->addMinutes(2), $resolver);
    }

    private function runHealthCheck(string $type, ProviderCredential $credential): void
    {
        match ($type) {
            'digitalocean', 'digitalocean_functions' => (new DigitalOceanService($credential))->validateToken(),
            'hetzner' => (new HetznerService($credential))->validateToken(),
            'linode', 'akamai' => (new LinodeService($credential))->validateToken(),
            'vultr' => (new VultrService($credential))->validateToken(),
            'scaleway' => (new ScalewayService($credential))->validateToken(),
            'upcloud' => (new UpCloudService($credential))->validateToken(),
            'equinix_metal' => (new EquinixMetalService($credential))->validateToken(),
            'aws' => (new AwsEc2Service($credential))->validateCredentials(),
            'fly_io' => (new FlyIoService($credential))->validateToken((string) (($credential->credentials ?? [])['org_slug'] ?? '')),
            default => throw new \InvalidArgumentException('Unsupported provider type for health check.'),
        };
    }

    /**
     * @return array{0:'ok'|'invalid'|'expired'|'under_scoped'|'rate_limited'|'misconfigured'|'unknown',1:'info'|'warning'|'error',2:string,3:string}
     */
    private function classifyFailure(string $type, Throwable $e): array
    {
        $message = strtolower(trim($e->getMessage()));

        if (str_contains($message, 'required') || str_contains($message, 'project id') || str_contains($message, 'org slug')) {
            return ['misconfigured', 'error', __('Credential setup is incomplete'), __('This provider credential is missing required configuration, so dply cannot verify or use it yet.')];
        }

        if (str_contains($message, 'expired') || str_contains($message, 'token has expired')) {
            return ['expired', 'error', __('Credential expired'), __('The provider rejected this credential because it appears to have expired. Reconnect or rotate it before provisioning.')];
        }

        if (str_contains($message, 'rate') && str_contains($message, 'limit')) {
            return ['rate_limited', 'warning', __('Provider rate limit reached'), __('The provider temporarily rate-limited validation requests. You can try again shortly or continue if you trust this credential.')];
        }

        if (str_contains($message, 'unauthorized') || str_contains($message, 'forbidden') || str_contains($message, 'permission') || str_contains($message, 'scope')) {
            return ['under_scoped', 'error', __('Credential lacks required access'), __('The provider accepted the request but denied access to the validation endpoint. This credential may be missing required scope or project access.')];
        }

        if (str_contains($message, 'invalid') || str_contains($message, 'authentication') || str_contains($message, 'signature') || str_contains($message, 'access key')) {
            return ['invalid', 'error', __('Credential validation failed'), __('The provider rejected this credential. Re-enter or rotate it before provisioning.')];
        }

        return ['unknown', 'warning', __('Credential could not be fully verified'), __('Dply could not confidently verify this provider credential just now. You can continue, but provisioning may still fail if the provider rejects the request.')];
    }

    /**
     * @return array{
     *     status: 'ok'|'invalid'|'expired'|'under_scoped'|'rate_limited'|'misconfigured'|'unknown',
     *     severity: 'info'|'warning'|'error',
     *     label: string,
     *     detail: string,
     *     provider_message: ?string,
     *     checked_at: ?string
     * }
     */
    private function result(
        string $status,
        string $severity,
        string $label,
        string $detail,
        ?string $providerMessage,
        ?string $checkedAt,
    ): array {
        return [
            'status' => $status,
            'severity' => $severity,
            'label' => $label,
            'detail' => $detail,
            'provider_message' => $providerMessage,
            'checked_at' => $checkedAt,
        ];
    }
}
