<?php

declare(strict_types=1);

namespace App\Actions\Serverless;

use App\Enums\ServerProvider;
use App\Enums\SiteType;
use App\Jobs\ProvisionServerlessHostJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One-shot "Create a serverless app" — the FaaS counterpart to
 * {@see App\Actions\Cloud\CreateCloudSiteFromSource}.
 *
 * Creates the host `Server` row (a DO Functions namespace — not a machine;
 * `host_kind = digitalocean_functions`) and the first function `Site` on it,
 * then kicks a deploy. The namespace is implementation detail the user never
 * names; subsequent functions attach to the same host via the normal
 * add-site path.
 *
 * Billing: per project_serverless_v1, the host is NOT spec-tiered — the
 * function-Site bills via the flat per-function serverless fee once it
 * reaches `functions_active`.
 */
class CreateServerlessFunction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, Organization $organization, array $payload): Site
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $slug = Str::slug($name) ?: 'fn-'.Str::lower(Str::random(6));

        $repo = $this->normalizeRepo((string) ($payload['repo'] ?? ''));
        if ($repo === '') {
            throw new InvalidArgumentException('A Git repository is required.');
        }

        $branch = trim((string) ($payload['branch'] ?? 'main')) ?: 'main';

        // `auto` (or an empty value) leaves the runtime unset so the
        // deploy-time ServerlessRuntimeDetector picks it from the repo. An
        // explicit value is stored verbatim and overrides detection.
        $runtime = trim((string) ($payload['runtime'] ?? ''));
        if ($runtime === 'auto') {
            $runtime = '';
        }
        $region = trim((string) ($payload['region'] ?? ''));
        $credentialId = trim((string) ($payload['provider_credential_id'] ?? ''));

        // Refuse to create a Functions host without a usable DigitalOcean
        // credential. The job that creates the OpenWhisk namespace short-
        // circuits when `providerCredential` is null and the server lands
        // in STATUS_ERROR with no actionable UX — better to fail loudly at
        // creation time. Verify the row exists, belongs to this org, and is
        // for DigitalOcean.
        if ($credentialId === '') {
            throw new InvalidArgumentException('A DigitalOcean credential is required to provision a serverless function. Add one at /credentials, then try again.');
        }
        $credential = ProviderCredential::query()
            ->where('id', $credentialId)
            ->where('organization_id', $organization->id)
            ->where('provider', 'digitalocean')
            ->first();
        if ($credential === null) {
            throw new InvalidArgumentException('The selected DigitalOcean credential is missing, belongs to another organization, or is not a DigitalOcean credential. Pick one from /credentials.');
        }

        // The serverless "host" — a DO Functions namespace. It starts PENDING;
        // ProvisionServerlessHostJob creates the namespace, fills in its
        // OpenWhisk credentials, marks it READY, then deploys the function.
        $server = Server::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider_credential_id' => $credential->id,
            'provider' => ServerProvider::DigitalOcean,
            'name' => 'functions-'.$slug,
            'region' => $region,
            'status' => Server::STATUS_PENDING,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            ],
        ]);

        // The function itself — a Site. Starts `functions_configured` (not yet
        // billable); the deploy moves it to `functions_active`. The Site
        // `creating` hook auto-attaches a Project.
        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $name !== '' ? $name : $slug,
            'slug' => $slug,
            'type' => SiteType::Node,
            'git_repository_url' => $repo,
            'git_branch' => $branch,
            'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => [
                    'runtime' => $runtime,
                    // OpenWhisk `exec.main` — the handler *function* name,
                    // not a file. dply's runtimes export `main`.
                    'entrypoint' => 'main',
                    'function_name' => $slug,
                    'repo_source' => 'manual',
                ],
            ],
        ]);

        // Give the function a friendly, dply-hosted URL straight away.
        $site->ensureServerlessProxySlug();

        // Mint the stable secret that authenticates background ticks, so it
        // exists before the first deploy bakes it into the function's env.
        $site->ensureServerlessCommandSecret();

        // Provision the namespace, then the job chains to the function deploy.
        ProvisionServerlessHostJob::dispatch($server->id);

        return $site;
    }

    /**
     * Accept an "owner/name" pair or a full GitHub URL; normalize to the
     * owner/name form the function deploy path expects.
     */
    private function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value, '/');
    }
}
