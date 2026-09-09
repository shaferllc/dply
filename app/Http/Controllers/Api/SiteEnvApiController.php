<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public HTTP API for a BYO/VM site's environment variables — the VM-site
 * so the CLI can manage `dply site env` for VM sites, not just Edge.
 *
 * Values live in the site's encrypted env cache (`sites.env_file_content`) and
 * are NEVER returned by GET (keys only), matching the Edge posture. A push /
 * deploy writes the resulting .env to the server afterwards.
 *
 * GET    /api/v1/sites/{site}/env           list keys (never values)
 * GET    /api/v1/sites/{site}/env/content   full .env body (sites.write — secrets)
 * PUT    /api/v1/sites/{site}/env/content   replace full .env body
 * PATCH  /api/v1/sites/{site}/env/{key}     set a single value
 * DELETE /api/v1/sites/{site}/env/{key}     remove
 */
class SiteEnvApiController extends Controller
{
    public function index(Request $request, Site $site, DotEnvFileParser $parser): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $keys = array_keys($parser->parse((string) ($site->env_file_content ?? ''))['variables']);
        $rows = array_map(fn (string $k): array => ['key' => $k, 'managed' => false], $keys);

        // Binding-injected keys (DB_HOST, REDIS_*, …) live outside the editable
        // cache — SiteEnvPusher composes them into the deployed .env. Surface
        // them as managed rows (key names only, same posture) so consumers see
        // the full key set the running app actually receives.
        $site->loadMissing('bindings');
        foreach ($site->bindings as $binding) {
            if ($binding->status !== SiteBinding::STATUS_CONFIGURED) {
                continue;
            }
            foreach (array_keys($binding->connectionEnv()) as $key) {
                if (! in_array($key, $keys, true)) {
                    $keys[] = $key;
                    $rows[] = ['key' => $key, 'managed' => true];
                }
            }
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Full editable .env cache for privileged clients (Production data mirror).
     * Requires sites.write — never expose on sites.read tokens.
     */
    public function showContent(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return response()->json([
            'data' => [
                'content' => (string) ($site->env_file_content ?? ''),
            ],
        ]);
    }

    public function updateContent(Request $request, Site $site, DotEnvFileParser $parser, DotEnvFileWriter $writer): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:524288'],
        ]);

        $map = $parser->parse((string) $data['content'])['variables'];
        $this->persist($site, $writer, $map);

        return response()->json([
            'data' => [
                'keys' => array_keys($map),
            ],
        ]);
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
