<?php

declare(strict_types=1);

namespace App\Actions\Serverless;

use App\Enums\ServerProvider;
use App\Enums\SiteType;
use App\Jobs\ProvisionServerlessHostJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One-shot "Create a serverless app" — the FaaS counterpart to
 * {@see App\Actions\Edge\CreateEdgeSiteFromSource}.
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
        $runtime = trim((string) ($payload['runtime'] ?? 'nodejs:18')) ?: 'nodejs:18';
        $region = trim((string) ($payload['region'] ?? ''));
        $credentialId = $payload['provider_credential_id'] ?? null;

        // The serverless "host" — a DO Functions namespace. It starts PENDING;
        // ProvisionServerlessHostJob creates the namespace, fills in its
        // OpenWhisk credentials, marks it READY, then deploys the function.
        $server = Server::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider_credential_id' => $credentialId !== '' ? $credentialId : null,
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
