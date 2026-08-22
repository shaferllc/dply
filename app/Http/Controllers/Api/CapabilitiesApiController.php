<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
 * to, not of the binary. An instance with a surface off simply does not
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
    public function show(Request $request): JsonResponse
    {
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
            ],
        ]]);
    }
}
