<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SocialAccount;
use App\Support\GitRemoteRepositoryRef;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RepositoryWebhookProvisioner
{
    public function __construct(
        private SiteDeploySyncCoordinator $syncCoordinator,
    ) {}

    public function canRegisterProviderHook(Site $site): bool
    {
        $group = $this->syncCoordinator->findGroupForSite($site);
        if ($group === null) {
            return true;
        }
        if ($group->leader_site_id === null) {
            return true;
        }

        return (string) $site->id === (string) $group->leader_site_id;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function enable(Site $site, SocialAccount $account): array
    {
        if (! $this->canRegisterProviderHook($site)) {
            return ['ok' => false, 'message' => __('Only the sync group leader can register a provider webhook. Deploys for other members run when the leader receives a push.')];
        }

        $repo = $site->repositoryMeta();
        $kind = (string) ($repo['git_provider_kind'] ?? 'custom');
        if ($kind === 'custom') {
            return ['ok' => false, 'message' => __('Choose GitHub, GitLab, or Bitbucket as the repository type to enable Quick deploy.')];
        }

        $url = trim((string) ($site->git_repository_url ?? ''));
        $ref = GitRemoteRepositoryRef::parse($url, $kind);
        if ($ref === null || $ref->provider === 'custom') {
            return ['ok' => false, 'message' => __('Could not parse the repository URL for this provider.')];
        }

        if ($site->webhook_secret === null || $site->webhook_secret === '') {
            $site->update(['webhook_secret' => Str::random(48)]);
            $site->refresh();
        }

        $hookUrl = $site->deployHookUrl();
        $secret = (string) $site->webhook_secret;

        $this->disable($site->fresh() ?? $site, $account);
        $site = $site->fresh();
        if ($site === null) {
            return ['ok' => false, 'message' => __('Site not found.')];
        }

        $result = match ($kind) {
            'github' => $this->createGitHubHook($site, $account, $ref, $hookUrl, $secret),
            'gitlab' => $this->createGitLabHook($site, $account, $ref, $hookUrl, $secret),
            'bitbucket' => $this->createBitbucketHook($site, $account, $ref, $hookUrl, $secret),
            default => ['ok' => false, 'message' => __('Unsupported provider.')],
        };

        if (! $result['ok']) {
            return $result;
        }

        $site->mergeRepositoryMeta([
            'quick_deploy_enabled' => true,
            'provider_hook' => [
                'id' => $result['hook_id'],
                'provider' => $kind,
                'account_id' => (string) $account->id,
            ],
        ]);
        $site->save();

        return ['ok' => true, 'message' => __('Quick deploy enabled. The provider will POST to your Dply deploy URL on push.')];
    }

    /**
     * @return array{ok: bool, message: string, hook_id?: string|int}
     */
    private function createGitHubHook(Site $site, SocialAccount $account, GitRemoteRepositoryRef $ref, string $hookUrl, string $secret): array
    {
        if ($ref->owner === null || $ref->repo === null) {
            return ['ok' => false, 'message' => __('Invalid GitHub repository path.')];
        }

        $token = trim((string) $account->access_token);
        if ($token === '' || $account->provider !== 'github') {
            return ['ok' => false, 'message' => __('Connect a GitHub account under Profile → Source control.')];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post('https://api.github.com/repos/'.$ref->owner.'/'.$ref->repo.'/hooks', [
                'name' => 'web',
                'active' => true,
                'events' => ['push'],
                'config' => [
                    'url' => $hookUrl,
                    'content_type' => 'json',
                    'insecure_ssl' => '0',
                    'secret' => $secret,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('GitHub webhook create failed', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

            return ['ok' => false, 'message' => __('GitHub rejected the webhook (:status). Re-link GitHub with repo and hook permissions, or check repository access.', ['status' => $response->status()])];
        }

        $id = $response->json('id');

        return ['ok' => true, 'message' => 'ok', 'hook_id' => $id];
    }

    /**
     * @return array{ok: bool, message: string, hook_id?: string|int}
     */
    private function createGitLabHook(Site $site, SocialAccount $account, GitRemoteRepositoryRef $ref, string $hookUrl, string $secret): array
    {
        if ($ref->gitlabProjectPath === null) {
            return ['ok' => false, 'message' => __('Invalid GitLab.com repository path.')];
        }

        $token = trim((string) $account->access_token);
        if ($token === '' || $account->provider !== 'gitlab') {
            return ['ok' => false, 'message' => __('Connect a GitLab account under Profile → Source control.')];
        }

        $encoded = rawurlencode($ref->gitlabProjectPath);

        $response = Http::withToken($token)
            ->acceptJson()
            ->post('https://gitlab.com/api/v4/projects/'.$encoded.'/hooks', [
                'url' => $hookUrl,
                'push_events' => true,
                'token' => $secret,
                'enable_ssl_verification' => true,
            ]);

        if (! $response->successful()) {
            Log::warning('GitLab webhook create failed', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

            return ['ok' => false, 'message' => __('GitLab rejected the webhook (:status). Re-link GitLab with API access.', ['status' => $response->status()])];
        }

        return ['ok' => true, 'message' => 'ok', 'hook_id' => $response->json('id')];
    }

    /**
     * @return array{ok: bool, message: string, hook_id?: string|int}
     */
    private function createBitbucketHook(Site $site, SocialAccount $account, GitRemoteRepositoryRef $ref, string $hookUrl, string $secret): array
    {
        if ($ref->owner === null || $ref->repo === null) {
            return ['ok' => false, 'message' => __('Invalid Bitbucket repository path.')];
        }

        $token = trim((string) $account->access_token);
        if ($token === '' || $account->provider !== 'bitbucket') {
            return ['ok' => false, 'message' => __('Connect a Bitbucket account under Profile → Source control.')];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post('https://api.bitbucket.org/2.0/repositories/'.$ref->owner.'/'.$ref->repo.'/hooks', [
                'description' => 'Dply deploy',
                'url' => $hookUrl,
                'active' => true,
                'events' => [
                    'repo:push',
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Bitbucket webhook create failed', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

            return ['ok' => false, 'message' => __('Bitbucket rejected the webhook (:status).', ['status' => $response->status()])];
        }

        $uuid = $response->json('uuid');
        $hookId = is_string($uuid) ? $uuid : (string) ($response->json('uuid') ?? '');

        return ['ok' => true, 'message' => 'ok', 'hook_id' => $hookId !== '' ? $hookId : 'bitbucket'];
    }

    public function disable(Site $site, ?SocialAccount $account = null): void
    {
        $repo = $site->repositoryMeta();
        $hook = is_array($repo['provider_hook'] ?? null) ? $repo['provider_hook'] : null;
        if ($hook === null) {
            $site->mergeRepositoryMeta(['quick_deploy_enabled' => false]);
            $site->save();

            return;
        }

        $provider = (string) ($hook['provider'] ?? '');
        $hookId = $hook['id'] ?? null;
        $accountId = (string) ($hook['account_id'] ?? '');

        if ($account === null && $accountId !== '') {
            $account = SocialAccount::query()->find($accountId);
        }

        $url = trim((string) ($site->git_repository_url ?? ''));
        $ref = GitRemoteRepositoryRef::parse($url, $provider !== '' ? $provider : 'github');

        try {
            if ($account && $hookId !== null && $ref !== null) {
                match ($provider) {
                    'github' => $this->deleteGitHubHook($account, $ref, $hookId),
                    'gitlab' => $this->deleteGitLabHook($account, $ref, $hookId),
                    'bitbucket' => $this->deleteBitbucketHook($account, $ref, $hookId),
                    default => null,
                };
            }
        } catch (\Throwable $e) {
            Log::warning('Provider webhook delete failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        $site->mergeRepositoryMeta([
            'quick_deploy_enabled' => false,
            'provider_hook' => null,
        ]);
        $site->save();
    }

    private function deleteGitHubHook(SocialAccount $account, GitRemoteRepositoryRef $ref, mixed $hookId): void
    {
        if ($ref->owner === null || $ref->repo === null) {
            return;
        }
        $token = trim((string) $account->access_token);
        if ($token === '') {
            return;
        }
        Http::withToken($token)
            ->delete('https://api.github.com/repos/'.$ref->owner.'/'.$ref->repo.'/hooks/'.$hookId);
    }

    private function deleteGitLabHook(SocialAccount $account, GitRemoteRepositoryRef $ref, mixed $hookId): void
    {
        if ($ref->gitlabProjectPath === null) {
            return;
        }
        $token = trim((string) $account->access_token);
        if ($token === '') {
            return;
        }
        $encoded = rawurlencode($ref->gitlabProjectPath);
        Http::withToken($token)
            ->delete('https://gitlab.com/api/v4/projects/'.$encoded.'/hooks/'.$hookId);
    }

    private function deleteBitbucketHook(SocialAccount $account, GitRemoteRepositoryRef $ref, mixed $hookId): void
    {
        if ($ref->owner === null || $ref->repo === null) {
            return;
        }
        $token = trim((string) $account->access_token);
        if ($token === '') {
            return;
        }
        $uuid = is_string($hookId) ? $hookId : (string) $hookId;
        $encoded = rawurlencode($uuid);
        Http::withToken($token)
            ->delete('https://api.bitbucket.org/2.0/repositories/'.$ref->owner.'/'.$ref->repo.'/hooks/'.$encoded);
    }

    /**
     * After rotating the Dply webhook secret, update the provider hook configuration when Quick deploy is enabled.
     */
    public function syncProviderHookSecret(Site $site): void
    {
        $repo = $site->repositoryMeta();
        if (! ($repo['quick_deploy_enabled'] ?? false)) {
            return;
        }
        $hook = is_array($repo['provider_hook'] ?? null) ? $repo['provider_hook'] : null;
        if ($hook === null) {
            return;
        }
        $accountId = (string) ($hook['account_id'] ?? '');
        $account = $accountId !== '' ? SocialAccount::query()->find($accountId) : null;
        if ($account === null) {
            return;
        }

        $provider = (string) ($hook['provider'] ?? '');
        $hookId = $hook['id'] ?? null;
        $url = trim((string) ($site->git_repository_url ?? ''));
        $ref = GitRemoteRepositoryRef::parse($url, $provider !== '' ? $provider : 'github');
        $secret = (string) $site->webhook_secret;
        if ($secret === '') {
            return;
        }

        if ($provider === 'github' && $ref?->owner && $ref?->repo && $hookId !== null) {
            $token = trim((string) $account->access_token);
            if ($token === '') {
                return;
            }
            Http::withToken($token)
                ->patch('https://api.github.com/repos/'.$ref->owner.'/'.$ref->repo.'/hooks/'.$hookId, [
                    'config' => [
                        'url' => $site->deployHookUrl(),
                        'content_type' => 'json',
                        'insecure_ssl' => '0',
                        'secret' => $secret,
                    ],
                ]);
        }

        if ($provider === 'gitlab' && $ref?->gitlabProjectPath !== null && $hookId !== null) {
            $token = trim((string) $account->access_token);
            if ($token === '') {
                return;
            }
            $encoded = rawurlencode($ref->gitlabProjectPath);
            Http::withToken($token)
                ->put('https://gitlab.com/api/v4/projects/'.$encoded.'/hooks/'.$hookId, [
                    'url' => $site->deployHookUrl(),
                    'push_events' => true,
                    'token' => $secret,
                    'enable_ssl_verification' => true,
                ]);
        }
    }
}
