<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\Site;
use App\Services\SourceControl\GitIdentityResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EdgeGithubWebhookProvisioner
{
    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= new GitIdentityResolver;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function enable(Site $site, GitIdentity $account): array
    {
        if (! $site->usesEdgeRuntime()) {
            return ['ok' => false, 'message' => __('This is not an Edge site.')];
        }

        if ($site->isEdgePreview()) {
            return ['ok' => false, 'message' => __('Branch previews do not register GitHub webhooks.')];
        }

        $repo = trim((string) ($site->edgeMeta()['source']['repo'] ?? ''));
        if ($repo === '' || ! str_contains($repo, '/')) {
            return ['ok' => false, 'message' => __('No Git repository is configured for this Edge site.')];
        }

        [$owner, $name] = array_pad(explode('/', $repo, 2), 2, '');
        $owner = trim($owner);
        $name = trim($name);
        if ($owner === '' || $name === '') {
            return ['ok' => false, 'message' => __('Invalid repository path.')];
        }

        if ($account->provider() !== 'github' || $account->accessToken() === '') {
            return ['ok' => false, 'message' => __('Connect a GitHub account or add a personal access token under Profile → Source control.')];
        }

        if ($site->webhook_secret === null || $site->webhook_secret === '') {
            $site->update(['webhook_secret' => Str::random(48)]);
            $site->refresh();
        }

        $this->disable($site, $account);

        $hookUrl = $site->edgeGithubHookUrl();
        $secret = (string) $site->webhook_secret;

        $response = Http::withToken($account->accessToken())
            ->acceptJson()
            ->post($account->apiBaseUrl().'/repos/'.$owner.'/'.$name.'/hooks', [
                'name' => 'web',
                'active' => true,
                'events' => ['push', 'pull_request'],
                'config' => [
                    'url' => $hookUrl,
                    'content_type' => 'json',
                    'insecure_ssl' => '0',
                    'secret' => $secret,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Edge GitHub webhook create failed', [
                'site_id' => $site->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $hint = $account->kind() === 'pat'
                ? __('GitHub rejected the webhook (:status). The personal access token needs admin:repo_hook scope (classic) or Read/Write Webhooks repo permission (fine-grained).', ['status' => $response->status()])
                : __('GitHub rejected the webhook (:status). Re-link GitHub with repo and hook permissions.', ['status' => $response->status()]);

            return [
                'ok' => false,
                'message' => $hint,
            ];
        }

        $hookId = $response->json('id');
        $site->mergeEdgeMeta([
            'webhook' => [
                'provider' => 'github',
                'hook_id' => $hookId,
                'account_id' => $account->id(),
                'status' => 'active',
                'enabled_at' => now()->toIso8601String(),
            ],
            'source' => array_merge(
                is_array($site->edgeMeta()['source'] ?? null) ? $site->edgeMeta()['source'] : [],
                ['deploy_on_push' => true],
            ),
        ]);
        $site->save();

        return ['ok' => true, 'message' => __('GitHub webhook connected. Push and pull request events will trigger deploys and previews.')];
    }

    public function disable(Site $site, ?GitIdentity $account = null): void
    {
        $webhook = is_array($site->edgeMeta()['webhook'] ?? null) ? $site->edgeMeta()['webhook'] : null;
        if ($webhook === null) {
            $site->mergeEdgeMeta(['webhook' => null]);
            $site->save();

            return;
        }

        $hookId = $webhook['hook_id'] ?? null;
        $accountId = (string) ($webhook['account_id'] ?? '');
        $repo = trim((string) ($site->edgeMeta()['source']['repo'] ?? ''));

        if ($account === null && $accountId !== '' && $site->user !== null) {
            $account = $this->resolver->forId($site->user, $accountId);
        }

        if ($account !== null && $hookId !== null && str_contains($repo, '/')) {
            [$owner, $name] = array_pad(explode('/', $repo, 2), 2, '');
            $token = $account->accessToken();
            if ($token !== '' && trim($owner) !== '' && trim($name) !== '') {
                try {
                    Http::withToken($token)
                        ->delete($account->apiBaseUrl().'/repos/'.$owner.'/'.$name.'/hooks/'.$hookId);
                } catch (\Throwable $e) {
                    Log::warning('Edge GitHub webhook delete failed', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $source = is_array($site->edgeMeta()['source'] ?? null) ? $site->edgeMeta()['source'] : [];
        $source['deploy_on_push'] = false;

        $site->mergeEdgeMeta([
            'webhook' => null,
            'source' => $source,
        ]);
        $site->save();
    }

    public function isConnected(Site $site): bool
    {
        $webhook = is_array($site->edgeMeta()['webhook'] ?? null) ? $site->edgeMeta()['webhook'] : null;

        return is_array($webhook) && ($webhook['hook_id'] ?? null) !== null;
    }
}
