<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Models\ApiToken;
use App\Models\Site;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Deploy\Services\ServerlessRuntimeDetector;
use App\Modules\Deploy\Services\ServerlessTargetCapabilityResolver;
use App\Modules\Serverless\Actions\CreateServerlessFunction;
use App\Modules\Serverless\Actions\DeleteServerlessFunction;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Serverless\Services\ServerlessCreateGate;
use App\Modules\Serverless\Services\ServerlessSourceStash;
use App\Models\SiteDeployment;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Support\Serverless\ServerlessWorkspaceUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Creating a serverless function over HTTP — what `dply init` drives.
 *
 * This is the first endpoint in the API that provisions anything, so it is
 * deliberately narrow: its own ability, its own feature flag, a per-organization
 * rate limit shared with delete, an idempotency key, an audit entry, and a
 * notification when a token (rather than a person in the dashboard) is the
 * actor.
 *
 * The interesting half is `dry_run`. It runs the identical gate chain and the
 * identical runtime detection the real create would, and returns both without
 * side effects — so the CLI can surface a missing DigitalOcean credential or a
 * paused trial *before* asking the user anything, and can show the runtime the
 * deploy will actually pick rather than a second guess computed in JavaScript.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
class ServerlessSiteCreateApiController extends ServerlessApiController
{
    public function __construct(
        private readonly ServerlessCreateGate $gate,
        private readonly CreateServerlessFunction $createFunction,
        private readonly ServerlessSourceStash $stash,
        private readonly ServerlessRuntimeDetector $detector,
        private readonly ServerlessTargetCapabilityResolver $capabilities,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();
        $dryRun = $request->boolean('dry_run');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source_kind' => ['nullable', Rule::in(['git', 'upload'])],
            'repo' => ['nullable', 'string', 'max:500'],
            'branch' => ['nullable', 'string', 'max:255'],
            'git_ref_kind' => ['nullable', Rule::in(['branch', 'tag', 'commit'])],
            'repo_source' => ['nullable', Rule::in(['manual', 'provider'])],
            'source_control_account_id' => ['nullable', 'string', 'max:26'],
            'repository_subdirectory' => ['nullable', 'string', 'max:255'],
            'runtime' => ['nullable', 'string', 'max:32'],
            'region' => ['nullable', 'string', 'max:32'],
            'delivery_mode' => ['nullable', Rule::in(['byo', 'managed'])],
            'provider_credential_id' => ['nullable', 'string', 'max:26'],
            // Secrets. Never logged, never echoed back.
            'env_file_content' => ['nullable', 'string', 'max:65535'],
            // A dry-run stash the CLI already uploaded, so the bytes go up once.
            'source_handle' => ['nullable', 'string', 'max:80'],
            'enable_push_to_deploy' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $sourceKind = (string) ($data['source_kind'] ?? 'git');
        $deliveryMode = (string) ($data['delivery_mode'] ?? ($this->gate->managedAvailable() ? 'managed' : 'byo'));
        $region = trim((string) ($data['region'] ?? '')) ?: (string) config('serverless.default_region', 'nyc1');

        $payload = [
            'name' => $data['name'],
            'source_kind' => $sourceKind,
            'repo' => trim((string) ($data['repo'] ?? '')),
            'branch' => trim((string) ($data['branch'] ?? 'main')) ?: 'main',
            'git_ref_kind' => $data['git_ref_kind'] ?? 'branch',
            'repo_source' => $data['repo_source'] ?? 'manual',
            'source_control_account_id' => $data['source_control_account_id'] ?? null,
            'repository_subdirectory' => trim((string) ($data['repository_subdirectory'] ?? ''), '/'),
            'runtime' => trim((string) ($data['runtime'] ?? 'auto')),
            'region' => $region,
            'delivery_mode' => $deliveryMode,
            'provider_credential_id' => $data['provider_credential_id'] ?? null,
            'env_file_content' => $data['env_file_content'] ?? null,
        ];

        if ($sourceKind === 'git' && $payload['repo'] === '') {
            return response()->json([
                'blocker' => [
                    'code' => 'source_required',
                    'message' => 'A repository is required for a git-source function. Upload the folder instead by passing source_kind=upload.',
                    'resolve_url' => null,
                    'resolve_command' => null,
                ],
            ], 422);
        }

        // Checked BEFORE the gate, not after: the create this key already
        // succeeded at consumes quota, so a retry that ran the gate first would
        // be told the quota is full instead of being handed back the site it
        // already made — the exact case an idempotency key exists for.
        $idempotencyKey = $dryRun ? '' : trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey !== '') {
            $cacheKey = 'serverless:create:'.$organization->id.':'.hash('sha256', $idempotencyKey);
            $existingId = Cache::get($cacheKey);
            if (is_string($existingId) && ($existing = Site::query()->find($existingId)) !== null) {
                return response()->json(['data' => $this->siteResource($existing), 'replayed' => true], 200);
            }
        }

        $blocker = $this->gate->check($user, $organization, $payload, ServerlessCreateGate::CONTEXT_API);
        if ($blocker !== null) {
            return response()->json(['blocker' => $blocker->toArray()], 422);
        }

        if ($dryRun) {
            return response()->json([
                'data' => [
                    'ok' => true,
                    'plan' => $this->plan($request, $payload, $organization),
                    'quota' => [
                        'used' => $organization->quotaUsage(\App\Enums\QuotaSurface::Serverless),
                        'limit' => $organization->quotaLimit(\App\Enums\QuotaSurface::Serverless),
                    ],
                ],
            ]);
        }

        // A dropped response on a create that provisions a billable namespace
        // otherwise means a retry makes a second one — and the orphan has no
        // .dply/site.json pointing at it, so it is found on a bill.
        // The gate already worked out which credential a BYO create would use
        // (an explicit id, else the org's preferred healthy one). Hand that
        // through rather than making the caller name one it should not have to
        // know about — `dply init` never asks for a credential.
        $payload['provider_credential_id'] = $this->gate
            ->resolveCredential($organization, $payload)?->id;

        try {
            $site = $this->createFunction->handle($user, $organization, $payload);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (isset($cacheKey)) {
            Cache::put($cacheKey, (string) $site->id, now()->addDay());
        }

        // Move the dry-run stash onto the site it created, so the upload the
        // CLI already spent is the one that gets built.
        if ($sourceKind === 'upload') {
            $handle = trim((string) ($data['source_handle'] ?? ''));
            if ($handle !== '') {
                $this->stash->promote($handle, 'site-'.$site->id);
            }
        }

        $webhook = $this->enablePushToDeploy($site, $sourceKind, (bool) ($data['enable_push_to_deploy'] ?? true));

        $token = $request->attributes->get('api_token');
        audit_log($organization, $user, 'serverless.function_created', $site, null, [
            'name' => (string) $site->name,
            'source_kind' => $sourceKind,
            'delivery_mode' => $deliveryMode,
            'region' => $region,
            'via' => 'api',
            'api_token_id' => $token instanceof ApiToken ? (string) $token->id : '',
        ]);

        $this->notifyAdmins($request, $site);

        return response()->json([
            'data' => $this->siteResource($site) + ['push_to_deploy' => $webhook],
        ], 201);
    }

    /**
     * Receive the project folder for an upload-source function.
     *
     * Used twice: by `dply init` before the site exists (no `{site}`, the
     * response carries a handle the create consumes), and by `dply deploy`
     * afterwards, which re-uploads and redeploys — an upload site's equivalent
     * of a git push.
     */
    public function source(Request $request, ?string $site = null): JsonResponse
    {
        $organization = $this->organization($request);

        $request->validate([
            'archive' => ['required', 'file', 'max:'.(int) ceil(((int) config('serverless.upload.max_bytes', 104857600)) / 1024)],
        ]);

        $upload = $request->file('archive');

        if ($site === null) {
            // Pre-create stash. Expires on its own if init is abandoned.
            $handle = 'stash-'.Str::lower((string) Str::ulid());
            try {
                $this->stash->put($handle, $upload->getRealPath());
            } catch (Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['data' => ['source_handle' => $handle]], 201);
        }

        $found = $this->findFunctionSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        if (! $organization->canDeploy()) {
            return response()->json(['message' => 'Deploys are paused for this organization.'], 422);
        }

        try {
            $this->stash->put('site-'.$found->id, $upload->getRealPath());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        RunSiteDeploymentJob::dispatch($found, SiteDeployment::TRIGGER_API);

        return response()->json(['data' => ['site_id' => (string) $found->id, 'deploying' => true]], 202);
    }

    /**
     * `dply init`'s undo — and only that.
     *
     * Refuses any function that has ever deployed successfully, which is what
     * makes it safe to expose without the name-typing confirmation the
     * dashboard requires: a leaked token cannot use this to destroy something
     * that ever served a request. Deleting a live function stays a dashboard
     * action.
     */
    public function destroy(Request $request, string $site, DeleteServerlessFunction $action): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $hasSucceeded = SiteDeployment::query()
            ->where('site_id', $found->id)
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->exists();

        if ($hasSucceeded || $found->status === Site::STATUS_FUNCTIONS_ACTIVE) {
            return response()->json([
                'message' => 'This function has deployed successfully, so it cannot be removed from the CLI. Delete it from the dashboard if you really mean to.',
                'resolve_url' => ServerlessWorkspaceUrl::journey($found),
            ], 409);
        }

        $organization = $this->organization($request);
        $name = (string) $found->name;

        // Audit before the delete — afterwards the subject is gone.
        audit_log($organization, $request->user(), 'serverless.function_deleted', $found, null, [
            'name' => $name,
            'via' => 'api',
        ]);

        $result = $action->handle($found);
        $this->stash->forget('site-'.$site);

        return response()->json(['data' => [
            'deleted' => true,
            'name' => $name,
            // A namespace or bucket dply could not reach keeps costing money,
            // so it is reported rather than swallowed into a clean success.
            'remote_error' => $result['remote_error'] ?? null,
            'bucket_error' => $result['bucket_error'] ?? null,
        ]]);
    }

    /**
     * What the deploy will actually decide — same detector, same capabilities.
     *
     * For a git source this clones a throwaway preview workspace, exactly as
     * the web create form does. For an upload it detects on the stash the CLI
     * just posted. Failures are reported, never fatal: detection is advice on
     * this screen, and the authoritative run happens at deploy time.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function plan(Request $request, array $payload, $organization): array
    {
        $capabilities = $this->capabilities->forDigitalOceanFunctions();
        $workspaceKey = 'preview-api-'.$organization->id.'-'.md5(json_encode([
            $payload['repo'], $payload['branch'], $payload['repository_subdirectory'],
        ]) ?: '');

        $directory = null;
        $cleanup = null;

        try {
            if (($payload['source_kind'] ?? 'git') === 'upload') {
                $handle = trim((string) $request->input('source_handle', ''));
                if ($handle === '' || ! $this->stash->has($handle)) {
                    return ['detected' => null, 'error' => 'No uploaded source to inspect.'];
                }
                $directory = storage_path('app/serverless-previews/'.$workspaceKey);
                $this->stash->materialize($handle, $directory);
                $cleanup = $directory;
            } else {
                $checkout = app(\App\Modules\Deploy\Services\ServerlessRepositoryCheckout::class)->checkout(
                    $workspaceKey,
                    (string) $payload['repo'],
                    (string) $payload['branch'],
                    (string) $payload['repository_subdirectory'],
                    $request->user()?->id,
                    $payload['source_control_account_id'] ?? null,
                    (string) ($payload['git_ref_kind'] ?? 'branch'),
                );
                $directory = $checkout['working_directory'];
                $cleanup = $checkout['workspace_path'];
            }

            $detected = $this->detector->detect($directory, $capabilities);

            return [
                'detected' => [
                    'framework' => $detected['framework'],
                    'language' => $detected['language'],
                    'runtime' => $detected['runtime'],
                    'entrypoint' => $detected['entrypoint'],
                    'entry_file' => $detected['entry_file'],
                    'build_command' => $detected['build_command'],
                    'deploy_kind' => $detected['deploy_kind'],
                    'confidence' => $detected['confidence'],
                    'reasons' => $detected['reasons'],
                    'warnings' => $detected['warnings'],
                    'unsupported_for_target' => (bool) $detected['unsupported_for_target'],
                    // Long-running signals. The CLI offers to switch on the
                    // matching engine rather than letting the user discover
                    // later that queued jobs never ran.
                    'laravel_horizon' => (bool) ($detected['laravel_horizon'] ?? false),
                    'laravel_reverb' => (bool) ($detected['laravel_reverb'] ?? false),
                    'laravel_octane' => (bool) ($detected['laravel_octane'] ?? false),
                ],
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['detected' => null, 'error' => $e->getMessage()];
        } finally {
            if ($cleanup !== null) {
                File::deleteDirectory($cleanup);
            }
        }
    }

    /**
     * Register the git webhook so a git-source site actually has push-to-deploy
     * rather than merely being able to have it.
     *
     * Never fatal: a repo dply cannot add a hook to is still a repo it can
     * deploy from on demand.
     *
     * @return array{enabled: bool, message: ?string}
     */
    private function enablePushToDeploy(Site $site, string $sourceKind, bool $wanted): array
    {
        if ($sourceKind === 'upload') {
            return [
                'enabled' => false,
                'message' => 'Uploaded sources have no remote to push to — redeploy with `dply deploy`.',
            ];
        }

        if (! $wanted) {
            return ['enabled' => false, 'message' => null];
        }

        try {
            $provider = self::providerKindFor((string) $site->git_repository_url);
            if ($provider === 'custom') {
                return ['enabled' => false, 'message' => 'Push-to-deploy needs a GitHub, GitLab, or Bitbucket repository.'];
            }

            // The provisioner reads the provider kind from stored meta rather
            // than the live URL, and a function created outside the connection
            // form has none — so record what we just resolved before enabling.
            $site->mergeRepositoryMeta(['git_provider_kind' => $provider]);
            $site->save();

            $resolver = app(GitIdentityResolver::class);
            $account = $resolver->forSite($site, $site->user, $provider);
            // Hook creation needs admin:repo_hook, which fine-grained PATs
            // rarely carry, so prefer an OAuth identity even when the repo
            // itself was connected with a token.
            $account = $resolver->forWebhooks($site->user, $provider, $account);

            if ($account === null) {
                return [
                    'enabled' => false,
                    'message' => 'Link a '.ucfirst($provider).' account to enable push-to-deploy.',
                ];
            }

            $result = app(RepositoryWebhookProvisioner::class)->enable($site, $account);

            return [
                'enabled' => $result['ok'],
                'message' => $result['message'],
            ];
        } catch (Throwable $e) {
            // A repo dply cannot add a hook to is still a repo it can deploy
            // from on demand, so this never fails the create.
            return ['enabled' => false, 'message' => $e->getMessage()];
        }
    }

    private static function providerKindFor(string $repositoryUrl): string
    {
        $url = strtolower(trim($repositoryUrl));

        return match (true) {
            $url === '' => 'custom',
            str_contains($url, 'github.com') => 'github',
            str_contains($url, 'gitlab.com') => 'gitlab',
            str_contains($url, 'bitbucket.org') => 'bitbucket',
            // Bare owner/name shorthand is expanded to GitHub everywhere else.
            preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $url) === 1 => 'github',
            default => 'custom',
        };
    }

    /**
     * Tell org admins when a *token* provisioned something billable.
     *
     * Account-scoped rather than site-scoped on purpose: notification
     * subscriptions target a server or a site, and nobody can have subscribed
     * to a site that did not exist a moment ago. A person who just clicked
     * Create in the dashboard is not notified — they were there.
     */
    private function notifyAdmins(Request $request, Site $site): void
    {
        $token = $request->attributes->get('api_token');
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
                eventKey: 'account.serverless.function_created',
                subject: $site,
                title: __('Function ":name" was created from the CLI', ['name' => (string) $site->name]),
                body: __('The API token ":token" created a serverless function in :org. It provisions a namespace that bills.', [
                    'token' => (string) $token->name,
                    'org' => (string) $organization->name,
                ]),
                url: ServerlessWorkspaceUrl::journey($site),
                metadata: [
                    'kind' => 'serverless_function_created',
                    'site_id' => (string) $site->id,
                    'api_token_id' => (string) $token->id,
                    'cta_label' => __('Open function'),
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
        $config = $site->serverlessConfig();

        return [
            'id' => (string) $site->id,
            'name' => (string) $site->name,
            'kind' => 'serverless',
            'status' => (string) $site->status,
            'source_kind' => $config['source_kind'] ?? 'git',
            'region' => (string) $site->server?->region,
            'delivery_mode' => $site->serverless_backend === Site::SERVERLESS_BACKEND_DPLY ? 'managed' : 'byo',
            'workspace_url' => ServerlessWorkspaceUrl::journey($site),
        ];
    }
}
