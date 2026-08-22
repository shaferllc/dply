<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\QuotaSurface;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Modules\Serverless\Services\ServerlessCreateGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

/**
 * What this dply instance can actually do — read by `dply init` before it
 * shows anything.
 *
 * The CLI deliberately hardcodes none of this. dply is self-hostable and one
 * installed CLI can address several instances (`--base-url`, `DPLY_BASE_URL`),
 * so surfaces, regions and limits are properties of the instance being talked
 * to, not of the binary. An instance with `surface.edge` off simply does not
 * offer Edge, and the region list has exactly one home in config.
 *
 * A 404 here means an instance older than this endpoint. The CLI treats that
 * as "no CLI create on this instance" and says so by name, rather than failing
 * obscurely — every other command keeps working against it.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
class CapabilitiesApiController extends Controller
{
    public function __construct(private readonly ServerlessCreateGate $gate) {}

    public function show(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');
        $serverlessEnabled = Feature::active('surface.serverless');
        $cliCreate = $serverlessEnabled && Feature::active('surface.serverless_cli_create');

        return response()->json(['data' => [
            'instance' => [
                'url' => config('app.url'),
                'name' => config('app.name'),
            ],
            // Per-kind so the menu can list every kind this instance has,
            // marking the ones the CLI cannot create yet — those open the web
            // wizard instead. The menu shape never changes as endpoints land;
            // a kind just stops opening a browser.
            'kinds' => [
                'vm' => [
                    'enabled' => true,
                    // `cli_create` is whether it is switched on *here*;
                    // `cli_create_supported` is whether this dply version has
                    // the endpoint at all. Without both, the CLI cannot tell
                    // "not built yet" from "an operator has not enabled it",
                    // and says the wrong one.
                    'cli_create_supported' => true,
                    'cli_create' => Feature::active('surface.vm_cli_create'),
                    'cli_create_flag' => 'FEATURE_SURFACE_VM_CLI_CREATE',
                    'create_url' => url('/servers'),
                    'requires_git' => false,
                    // A site has to live on a server, so the CLI picks one
                    // before it can ask anything else.
                    'requires_server' => true,
                ],
                'cloud' => [
                    'enabled' => Feature::active('surface.cloud'),
                    'cli_create_supported' => true,
                    'cli_create' => Feature::active('surface.cloud')
                        && Feature::active('surface.cloud_cli_create'),
                    'cli_create_flag' => 'FEATURE_SURFACE_CLOUD_CLI_CREATE',
                    'create_url' => url('/cloud/create'),
                    // A container backend clones and builds the repository
                    // itself, so dply never holds the source: unlike a
                    // function, a cloud app cannot be created from a folder
                    // with no remote.
                    'requires_git' => true,
                ],
                'edge' => [
                    'enabled' => Feature::active('surface.edge'),
                    'cli_create_supported' => false,
                    'cli_create' => false,
                    'create_url' => url('/edge/create'),
                ],
                'serverless' => [
                    'enabled' => $serverlessEnabled,
                    'cli_create_supported' => true,
                    'cli_create' => $cliCreate,
                    'cli_create_flag' => 'FEATURE_SURFACE_SERVERLESS_CLI_CREATE',
                    'create_url' => url('/serverless/create'),
                    'requires_git' => false,
                ],
            ],
            'serverless' => [
                'regions' => (array) config('serverless.regions', []),
                'default_region' => (string) config('serverless.default_region', 'nyc1'),
                'managed_available' => $this->gate->managedAvailable(),
                'upload' => [
                    'max_bytes' => (int) config('serverless.upload.max_bytes', 104857600),
                    'max_entries' => (int) config('serverless.upload.max_entries', 20000),
                ],
                'quota' => $organization instanceof Organization ? [
                    'used' => $organization->quotaUsage(QuotaSurface::Serverless),
                    'limit' => $organization->quotaLimit(QuotaSurface::Serverless),
                ] : null,
            ],
        ]]);
    }
}
