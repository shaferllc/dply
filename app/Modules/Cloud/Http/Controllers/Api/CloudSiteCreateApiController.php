<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Http\Controllers\Api;

use App\Enums\QuotaSurface;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\CloudDeployTask;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Cloud\Actions\CreateCloudSite;
use App\Modules\Cloud\Actions\CreateCloudSiteFromSource;
use App\Modules\Cloud\Backends\AwsAppRunnerBackend;
use App\Modules\Cloud\Backends\DigitalOceanAppPlatformBackend;
use App\Modules\Cloud\Services\CloudCreateGate;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Creating a managed container app over HTTP — the Cloud half of what
 * `dply init` drives.
 *
 * Deliberately the same shape as
 * {@see \App\Modules\Serverless\Http\Controllers\Api\ServerlessSiteCreateApiController}:
 * its own ability, its own feature flag, the shared per-organization create
 * limiter, an idempotency key, a typed blocker on `422`, and a `dry_run` that
 * runs the identical gate chain with no side effects. A caller that has learned
 * one create endpoint has learned both.
 *
 * One thing Cloud cannot borrow from Serverless: **there is no uploaded-source
 * mode**. The container backend (DO App Platform / AWS App Runner) clones and
 * builds the repository itself — dply never holds the source — so a folder with
 * no reachable git remote genuinely cannot become a cloud app. `dply init` says
 * that rather than pretending otherwise.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
class CloudSiteCreateApiController extends Controller
{
    public function __construct(private readonly CloudCreateGate $gate) {}

    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();
        $dryRun = $request->boolean('dry_run');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mode' => ['nullable', Rule::in(['source', 'image'])],
            'repo' => ['nullable', 'string', 'max:500'],
            'branch' => ['nullable', 'string', 'max:255'],
            'dockerfile_path' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:500'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'instances' => ['nullable', 'integer', 'min:1', 'max:50'],
            'size_tier' => ['nullable', Rule::in(array_keys(CloudDeployTask::SIZE_TIERS))],
            'region' => ['nullable', 'string', 'max:32'],
            'backend' => ['nullable', 'string', 'max:64'],
            'deploy_on_push' => ['nullable', 'boolean'],
            // Secrets. Never logged, never echoed back.
            'env_file_content' => ['nullable', 'string', 'max:65535'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $mode = (string) ($data['mode'] ?? 'source');

        $payload = [
            'name' => $data['name'],
            'mode' => $mode,
            'repo' => trim((string) ($data['repo'] ?? '')),
            'branch' => trim((string) ($data['branch'] ?? 'main')) ?: 'main',
            'dockerfile_path' => trim((string) ($data['dockerfile_path'] ?? '')),
            'image' => trim((string) ($data['image'] ?? '')),
            'port' => (int) ($data['port'] ?? 8080),
            'instances' => (int) ($data['instances'] ?? 1),
            'size_tier' => (string) ($data['size_tier'] ?? 'small'),
            'region' => trim((string) ($data['region'] ?? '')),
            'backend' => trim((string) ($data['backend'] ?? 'auto')) ?: 'auto',
            'deploy_on_push' => ! array_key_exists('deploy_on_push', $data) || (bool) $data['deploy_on_push'],
            'env_file_content' => $data['env_file_content'] ?? null,
        ];

        // A container app is built from a repository by the backend itself.
        // There is no upload alternative to offer here, so the message says
        // what to do rather than what is missing.
        if ($mode === 'source' && $payload['repo'] === '') {
            return response()->json(['blocker' => [
                'code' => 'source_required',
                'message' => 'A cloud app builds from a git repository — push this folder to a remote first, or pass an image with mode=image.',
                'resolve_url' => null,
                'resolve_command' => null,
            ]], 422);
        }

        if ($mode === 'image' && $payload['image'] === '') {
            return response()->json(['blocker' => [
                'code' => 'source_required',
                'message' => 'mode=image needs an image reference.',
                'resolve_url' => null,
                'resolve_command' => null,
            ]], 422);
        }

        // Checked BEFORE the gate, not after: the create this key already
        // succeeded at consumes quota, so a retry that ran the gate first would
        // be told the quota is full instead of being handed back the site it
        // already made — the exact case an idempotency key exists for.
        $idempotencyKey = $dryRun ? '' : trim((string) $request->header('Idempotency-Key', ''));
        $cacheKey = null;
        if ($idempotencyKey !== '') {
            $cacheKey = 'cloud:create:'.$organization->id.':'.hash('sha256', $idempotencyKey);
            $existingId = Cache::get($cacheKey);
            if (is_string($existingId) && ($existing = Site::query()->find($existingId)) !== null) {
                return response()->json(['data' => $this->siteResource($existing), 'replayed' => true], 200);
            }
        }

        $blocker = $this->gate->check($user, $organization, $payload, CloudCreateGate::CONTEXT_API);
        if ($blocker !== null) {
            return response()->json(['blocker' => $blocker->toArray()], 422);
        }

        $backend = $this->gate->resolveBackend($organization, $payload);
        $regions = $this->regionsFor($backend);

        if ($payload['region'] !== '' && $regions !== []
            && ! in_array($payload['region'], array_column($regions, 'slug'), true)) {
            return response()->json(['blocker' => [
                'code' => 'invalid_region',
                'message' => sprintf(
                    '"%s" is not a region %s runs in. Available: %s',
                    $payload['region'],
                    $backend,
                    implode(', ', array_column($regions, 'slug')),
                ),
                'resolve_url' => null,
                'resolve_command' => null,
            ]], 422);
        }

        if ($dryRun) {
            return response()->json(['data' => [
                'ok' => true,
                'plan' => [
                    'backend' => $backend,
                    'mode' => $mode,
                    'regions' => $regions,
                    'size_tiers' => array_keys(CloudDeployTask::SIZE_TIERS),
                    'size_tier' => $payload['size_tier'],
                    'port' => $payload['port'],
                    // The wizard additionally pre-flights the spec against the
                    // provider (/apps/propose) to catch rejections before a Site
                    // row exists. That path lives in the shell, so a spec
                    // rejection surfaces here on the real create instead.
                    'spec_preflight' => false,
                ],
                'quota' => [
                    'used' => $organization->quotaUsage(QuotaSurface::Cloud),
                    'limit' => $organization->quotaLimit(QuotaSurface::Cloud),
                ],
            ]]);
        }

        $payload['backend'] = $backend ?? 'auto';

        try {
            $site = $mode === 'source'
                ? app(CreateCloudSiteFromSource::class)->handle($user, $organization, $payload)
                : app(CreateCloudSite::class)->handle($user, $organization, $payload);
        } catch (Throwable $e) {
            return response()->json(['blocker' => [
                'code' => 'spec_rejected',
                'message' => $e->getMessage(),
                'resolve_url' => null,
                'resolve_command' => null,
            ]], 422);
        }

        if ($cacheKey !== null) {
            Cache::put($cacheKey, (string) $site->id, now()->addDay());
        }

        $token = $request->attributes->get('api_token');

        audit_log($organization, $user, 'cloud.site_created', $site, null, [
            'name' => (string) $site->name,
            'mode' => $mode,
            'backend' => (string) $backend,
            'via' => 'api',
            'api_token_id' => $token instanceof ApiToken ? (string) $token->id : '',
        ]);

        $this->notifyAdmins($request, $site, $token);

        return response()->json(['data' => $this->siteResource($site)], 201);
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
     * @return list<array{slug: string, label: string}>
     */
    private function regionsFor(?string $backend): array
    {
        return match ($backend) {
            'digitalocean_app_platform' => (new DigitalOceanAppPlatformBackend)->regions(),
            'aws_app_runner' => (new AwsAppRunnerBackend)->regions(),
            default => [],
        };
    }

    private function notifyAdmins(Request $request, Site $site, mixed $token): void
    {
        if (! $token instanceof ApiToken) {
            return;
        }

        $organization = $site->organization;
        if ($organization === null) {
            return;
        }

        try {
            $adminIds = $organization->users()
                ->wherePivotIn('role', ['owner', 'admin'])
                ->pluck('users.id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if ($adminIds === []) {
                return;
            }

            app(NotificationPublisher::class)->publish(
                eventKey: 'account.cloud.site_created',
                subject: $site,
                title: __('Cloud app ":name" was created from the CLI', ['name' => (string) $site->name]),
                body: __('The API token ":token" created a container app in :org. It provisions infrastructure that bills.', [
                    'token' => (string) $token->name,
                    'org' => (string) $organization->name,
                ]),
                url: url('/sites/'.$site->id),
                metadata: [
                    'kind' => 'cloud_site_created',
                    'site_id' => (string) $site->id,
                    'api_token_id' => (string) $token->id,
                ],
                actor: $request->user(),
                recipientUsers: $adminIds,
            );
        } catch (Throwable) {
            // A notification must never fail a create that already provisioned.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function siteResource(Site $site): array
    {
        return [
            'id' => (string) $site->id,
            'name' => (string) $site->name,
            'kind' => 'cloud',
            'status' => (string) $site->status,
            'region' => (string) $site->server?->region,
            'workspace_url' => url('/sites/'.$site->id),
        ];
    }
}
