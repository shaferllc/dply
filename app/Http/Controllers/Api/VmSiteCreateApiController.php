<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\QuotaSurface;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\CreateVmSite;
use App\Services\Sites\VmCreateGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Creating a site on a server the organization owns — the BYO half of what
 * `dply init` drives.
 *
 * Same contract as the serverless and cloud creates: own ability, own feature
 * flag, the shared per-organization create limiter, `Idempotency-Key`, typed
 * blockers on `422`, and a `dry_run` that runs the gate chain with no side
 * effects.
 *
 * Narrower than those two on purpose. It creates on ordinary webserver hosts
 * only; a functions, Docker, Kubernetes, or headless host returns a
 * `host_unsupported` blocker pointing at the dashboard, because those need
 * host-specific configuration the wizard builds and this endpoint would only
 * be guessing at. See {@see CreateVmSite}.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
class VmSiteCreateApiController extends Controller
{
    public function __construct(
        private readonly VmCreateGate $gate,
        private readonly CreateVmSite $createSite,
    ) {}

    public function store(Request $request, string $server): JsonResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();
        $dryRun = $request->boolean('dry_run');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['php', 'static', 'node'])],
            'document_root' => ['nullable', 'string', 'max:500'],
            'primary_hostname' => ['nullable', 'string', 'max:255'],
            'git_repository_url' => ['nullable', 'string', 'max:500'],
            'git_branch' => ['nullable', 'string', 'max:255'],
            'runtime' => ['nullable', 'string', 'max:50'],
            'runtime_version' => ['nullable', 'string', 'max:20'],
            'build_command' => ['nullable', 'string', 'max:4000'],
            'start_command' => ['nullable', 'string', 'max:4000'],
            'app_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'framework' => ['nullable', 'string', 'max:60'],
            // Secrets. Never logged, never echoed back.
            'env_file_content' => ['nullable', 'string', 'max:65535'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $found = Server::query()
            ->where('organization_id', $organization->id)
            ->find($server);

        $type = (string) ($data['type'] ?? 'php');
        $name = (string) $data['name'];

        $payload = [
            'name' => $name,
            'type' => $type,
            // A site's files land under the deploy path unless told otherwise;
            // /public is the near-universal PHP layout and the wizard's own
            // default shape.
            'document_root' => trim((string) ($data['document_root'] ?? ''))
                ?: self::defaultDocumentRoot($name, $type, (string) ($data['primary_hostname'] ?? '')),
            'primary_hostname' => $data['primary_hostname'] ?? '',
            'git_repository_url' => $data['git_repository_url'] ?? '',
            'git_branch' => $data['git_branch'] ?? 'main',
            'runtime' => $data['runtime'] ?? '',
            'runtime_version' => $data['runtime_version'] ?? '',
            'build_command' => $data['build_command'] ?? '',
            'start_command' => $data['start_command'] ?? '',
            'app_port' => $data['app_port'] ?? null,
            'framework' => $data['framework'] ?? '',
            'env_file_content' => $data['env_file_content'] ?? null,
        ];

        // Checked BEFORE the gate, not after: the create this key already
        // succeeded at consumes quota, so a retry that ran the gate first would
        // be told the quota is full instead of being handed back the site it
        // already made — the exact case an idempotency key exists for.
        $idempotencyKey = $dryRun ? '' : trim((string) $request->header('Idempotency-Key', ''));
        $cacheKey = null;
        if ($idempotencyKey !== '') {
            $cacheKey = 'vm:create:'.$organization->id.':'.hash('sha256', $idempotencyKey);
            $existingId = Cache::get($cacheKey);
            if (is_string($existingId) && ($existing = Site::query()->find($existingId)) !== null) {
                return response()->json(['data' => $this->siteResource($existing), 'replayed' => true], 200);
            }
        }

        $blocker = $this->gate->check($user, $organization, $found, $payload, VmCreateGate::CONTEXT_API);
        if ($blocker !== null) {
            return response()->json(['blocker' => $blocker->toArray()], 422);
        }

        if ($dryRun) {
            return response()->json(['data' => [
                'ok' => true,
                'plan' => [
                    'server' => ['id' => (string) $found->id, 'name' => (string) $found->name],
                    'type' => $type,
                    'document_root' => $payload['document_root'],
                    'webserver' => (string) ($found->meta['webserver'] ?? 'nginx'),
                ],
                'quota' => [
                    'used' => $organization->quotaUsage(QuotaSurface::Site),
                    'limit' => $organization->quotaLimit(QuotaSurface::Site),
                ],
            ]]);
        }

        try {
            $site = $this->createSite->handle($user, $found, $payload);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($cacheKey !== null) {
            Cache::put($cacheKey, (string) $site->id, now()->addDay());
        }

        $token = $request->attributes->get('api_token');

        audit_log($organization, $user, 'site.created', $site, null, [
            'name' => (string) $site->name,
            'server_id' => (string) $found->id,
            'via' => 'api',
            'api_token_id' => $token instanceof ApiToken ? (string) $token->id : '',
        ]);

        return response()->json(['data' => $this->siteResource($site)], 201);
    }

    /**
     * Mirrors {@see \App\Models\Site::conventionalRepositoryPath()} —
     * `/home/dply/<hostname>`, falling back to the slug when no domain was
     * given — because the document root has to be supplied at create time,
     * before the Site row exists to compute it from.
     *
     * PHP apps are served from `public/`; static and Node apps from the
     * release root.
     */
    private static function defaultDocumentRoot(string $name, string $type, string $hostname): string
    {
        $host = strtolower(trim($hostname));
        $host = trim((string) preg_replace('/[^a-z0-9.-]+/', '', $host), '.-');

        if ($host === '') {
            $host = \Illuminate\Support\Str::slug($name) ?: 'site';
        }

        $base = '/home/dply/'.$host;

        return $type === 'php' ? $base.'/public' : $base;
    }

    protected function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('api_organization');
        if (! $organization instanceof Organization) {
            abort(401);
        }

        return $organization;
    }

    /**
     * @return array<string, mixed>
     */
    private function siteResource(Site $site): array
    {
        return [
            'id' => (string) $site->id,
            'name' => (string) $site->name,
            'kind' => 'vm',
            'status' => (string) $site->status,
            'server_id' => (string) $site->server_id,
            'workspace_url' => url('/servers/'.$site->server_id.'/sites/'.$site->id),
        ];
    }
}
