<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public HTTP API for a BYO/VM site's environment variables — the VM-site
 * counterpart to {@see \App\Modules\Edge\Http\Controllers\Api\EdgeEnvController},
 * so the CLI can manage `dply site env` for VM sites, not just Edge.
 *
 * Values live in the site's encrypted env cache (`sites.env_file_content`) and
 * are NEVER returned by GET (keys only), matching the Edge posture. A push /
 * deploy writes the resulting .env to the server afterwards.
 *
 * GET    /api/v1/sites/{site}/env           list keys (never values)
 * PATCH  /api/v1/sites/{site}/env/{key}     set a single value
 * DELETE /api/v1/sites/{site}/env/{key}     remove
 */
class SiteEnvApiController extends Controller
{
    public function index(Request $request, Site $site, DotEnvFileParser $parser): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $keys = array_keys($parser->parse((string) ($site->env_file_content ?? ''))['variables']);

        return response()->json(['data' => array_map(fn (string $k): array => ['key' => $k], $keys)]);
    }

    public function upsert(Request $request, Site $site, string $key, DotEnvFileParser $parser, DotEnvFileWriter $writer): JsonResponse
    {
        $this->authorizeSite($request, $site);

        if (! preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
            return response()->json(['message' => 'KEY must match /^[A-Z_][A-Z0-9_]*$/i.'], 422);
        }

        $value = $request->json('value');
        if (! is_scalar($value) && $value !== null) {
            return response()->json(['message' => 'Body must include a string `value`.'], 422);
        }

        $map = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        $map[$key] = (string) ($value ?? '');
        $this->persist($site, $writer, $map);

        return response()->json(['data' => ['key' => $key]]);
    }

    public function destroy(Request $request, Site $site, string $key, DotEnvFileParser $parser, DotEnvFileWriter $writer): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $map = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        $existed = array_key_exists($key, $map);
        unset($map[$key]);
        $this->persist($site, $writer, $map);

        return response()->json(['deleted' => $existed ? 1 : 0]);
    }

    /** @param  array<string, string>  $map */
    private function persist(Site $site, DotEnvFileWriter $writer, array $map): void
    {
        $site->forceFill([
            'env_file_content' => $writer->render($map),
            'env_cache_origin' => 'local-edit',
        ])->save();
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        $organization = $request->attributes->get('api_organization');
        abort_if($organization === null || $site->server?->organization_id !== $organization->id, 403);
    }
}
