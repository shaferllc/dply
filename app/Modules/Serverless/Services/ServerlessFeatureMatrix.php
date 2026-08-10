<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Serverless\Contracts\DeclaresServerlessFeatures;
use App\Modules\Serverless\Contracts\ServerlessFeature;

/**
 * What a serverless host can actually do.
 *
 * The workspace asks this instead of branching on host kind, so a control is
 * hidden (or shown disabled with a reason) wherever the backend behind it
 * cannot honour the setting. A backend object that declares its own set —
 * anything implementing {@see DeclaresServerlessFeatures} — is authoritative;
 * the per-host tables below are the fallback for the plain-Server case, where
 * no backend has been resolved yet (e.g. during create, before a namespace
 * exists).
 */
class ServerlessFeatureMatrix
{
    /**
     * DigitalOcean Functions — the reference host.
     *
     * Everything except {@see ServerlessFeature::NamespaceAccessKeys}: keys
     * are created and revoked through the control panel and `doctl`, and
     * DigitalOcean publishes no REST API for it, so dply cannot mint one on
     * an operator's behalf. The Platform tab shows the key dply holds and
     * the commands to rotate it instead.
     *
     * @return list<ServerlessFeature>
     */
    public static function digitalOceanFunctions(): array
    {
        return array_values(array_filter(
            ServerlessFeature::all(),
            static fn (ServerlessFeature $feature): bool => $feature !== ServerlessFeature::NamespaceAccessKeys,
        ));
    }

    /**
     * AWS Lambda. Conservative on purpose: a feature is listed only where
     * dply already has the implementation, because a capability claimed here
     * becomes a control the operator can toggle.
     *
     * @return list<ServerlessFeature>
     */
    public static function awsLambda(): array
    {
        return [
            ServerlessFeature::WebFunction,
            ServerlessFeature::CustomCors,
            ServerlessFeature::DefaultParameters,
            ServerlessFeature::AsyncInvocation,
            ServerlessFeature::ScheduledTriggers,
            ServerlessFeature::Sequences,
        ];
    }

    /**
     * @return list<ServerlessFeature>
     */
    public function forServer(?Server $server): array
    {
        if (! $server instanceof Server) {
            return [];
        }

        if ($server->isDigitalOceanFunctionsHost()) {
            return self::digitalOceanFunctions();
        }

        if ($server->isAwsLambdaHost()) {
            return self::awsLambda();
        }

        return [];
    }

    /**
     * @return list<ServerlessFeature>
     */
    public function forSite(Site $site): array
    {
        $site->loadMissing('server');

        return $this->forServer($site->server);
    }

    public function serverSupports(?Server $server, ServerlessFeature $feature): bool
    {
        return in_array($feature, $this->forServer($server), true);
    }

    public function siteSupports(Site $site, ServerlessFeature $feature): bool
    {
        return in_array($feature, $this->forSite($site), true);
    }

    /**
     * Ask a resolved backend directly. Anything that does not declare its
     * features is treated as supporting nothing — the same default as the
     * trait, so an un-migrated backend degrades to "hidden", never to a
     * control that silently no-ops.
     */
    public function backendSupports(mixed $backend, ServerlessFeature $feature): bool
    {
        return $backend instanceof DeclaresServerlessFeatures
            && $backend->supportsServerlessFeature($feature);
    }

    /**
     * The features a host is missing from a required set — what the caller
     * names in an "unsupported on this host" message.
     *
     * @param  list<ServerlessFeature>  $required
     * @return list<ServerlessFeature>
     */
    public function missingForServer(?Server $server, array $required): array
    {
        $supported = $this->forServer($server);

        return array_values(array_filter(
            $required,
            static fn (ServerlessFeature $feature): bool => ! in_array($feature, $supported, true),
        ));
    }
}
