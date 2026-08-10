<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Serverless\Contracts\ServerlessFeature;
use App\Modules\Serverless\Contracts\SupportsFunctionConfiguration;
use App\Modules\Serverless\Support\FunctionConfiguration;

/**
 * Pushes a function's HTTP-exposure configuration to the live action.
 *
 * Deploy applies the same configuration as part of its upload, so this exists
 * for the edit case: toggling CORS or rotating the endpoint secret should take
 * effect now, not on whatever deploy happens next. When the host cannot do a
 * live update — or the function has never been deployed — this reports that
 * plainly instead of failing, and the change still lands on the next deploy.
 */
class ServerlessFunctionConfigurator
{
    public function __construct(
        private readonly ServerlessFeatureMatrix $features,
        private readonly ServerlessProvisionerLocator $provisioners,
    ) {}

    /**
     * @return array{ok: bool, error: ?string, applied: bool}
     */
    public function apply(Site $site): array
    {
        $site->loadMissing('server');
        $server = $site->server;

        if (! $server instanceof Server) {
            return ['ok' => false, 'error' => __('This function has no host.'), 'applied' => false];
        }

        $configuration = FunctionConfiguration::fromSiteConfig($site->serverlessConfig());

        $missing = $this->features->missingForServer($server, $configuration->featuresRequired());
        if ($missing !== []) {
            $names = implode(', ', array_map(
                static fn (ServerlessFeature $feature): string => $feature->label(),
                $missing,
            ));

            return [
                'ok' => false,
                'error' => __(':host does not support: :features.', [
                    'host' => $server->name ?: __('This host'),
                    'features' => $names,
                ]),
                'applied' => false,
            ];
        }

        $actionName = trim((string) ($site->serverlessConfig()['action_name'] ?? ''));
        $deployed = trim((string) ($site->serverlessConfig()['last_revision_id'] ?? '')) !== '';

        // Nothing to patch until the action exists — the saved configuration
        // is picked up by the first deploy.
        if ($actionName === '' || ! $deployed) {
            return ['ok' => true, 'error' => null, 'applied' => false];
        }

        $provisioner = $this->provisioners->forSite($site);

        if (! $provisioner instanceof SupportsFunctionConfiguration) {
            return ['ok' => true, 'error' => null, 'applied' => false];
        }

        $result = $provisioner->applyFunctionConfiguration(
            $actionName,
            $configuration,
            $this->provisioners->contextForSite($site),
        );

        return [
            'ok' => (bool) $result['ok'],
            'error' => $result['ok'] ? null : (string) ($result['error'] ?? __('The host rejected the configuration.')),
            'applied' => (bool) $result['ok'],
        ];
    }
}
