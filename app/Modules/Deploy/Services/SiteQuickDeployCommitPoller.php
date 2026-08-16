<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\GitProviderToken;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SocialAccount;
use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Services\Sites\SiteDeployCoordinator;
use App\Support\GitRemoteRepositoryRef;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Control-plane Quick deploy poll: resolve the deploy-branch tip via the Git
 * provider API (no SSH), compare to the last successful deployment SHA, and
 * queue a deploy when they differ.
 */
final class SiteQuickDeployCommitPoller
{
    public const POLL_LOG_MAX = 25;

    /** @var list<string> */
    public const OUTCOMES = [
        'unchanged',
        'deploy_queued',
        'skipped_in_progress',
        'error',
    ];

    public function __construct(
        private SiteDeployCoordinator $coordinator,
    ) {}

    /**
     * @return array{checked: bool, dispatched: bool, tip: string|null, reason: string|null, outcome: string|null, message: string|null}
     */
    public function poll(Site $site): array
    {
        $repo = $site->repositoryMeta();
        if (! ($repo['quick_deploy_enabled'] ?? false)) {
            return $this->result(false, false, null, 'disabled');
        }
        if (($repo['quick_deploy_mode'] ?? 'webhook') !== 'poll') {
            return $this->result(false, false, null, 'not_poll_mode');
        }

        $server = $site->server;
        if ($server === null || ! $server->isVmHost() || $site->usesFunctionsRuntime() || $site->usesEdgeRuntime()) {
            return $this->result(false, false, null, 'unsupported_runtime');
        }

        if (trim((string) $site->git_repository_url) === '') {
            return $this->result(false, false, null, 'no_repository');
        }

        if ($this->coordinator->inProgress($site)) {
            $tip = (string) ($repo['poll_last_tip_sha'] ?? '');
            $message = __('Deploy already in progress');
            $this->recordCheck($site, tip: $tip, outcome: 'skipped_in_progress', message: $message, skipReason: 'deploy_in_progress');

            return $this->result(true, false, $tip !== '' ? $tip : null, 'deploy_in_progress', 'skipped_in_progress', $message);
        }

        $resolved = $this->resolveTipShaDetailed($site);
        $tip = $resolved['sha'];
        if ($tip === null || $tip === '') {
            $httpStatus = $resolved['http_status'] ?? null;
            $errorKind = $this->errorKindFromResolved($resolved);
            $message = $this->tipUnresolvedMessage($resolved);
            $this->recordCheck(
                $site,
                tip: '',
                outcome: 'error',
                message: $message,
                skipReason: 'tip_unresolved',
                httpStatus: $httpStatus,
                errorKind: $errorKind,
            );

            return $this->result(true, false, null, 'tip_unresolved', 'error', $message);
        }

        $lastSha = $this->lastSuccessfulSha($site);

        if ($lastSha !== null && $lastSha !== '' && hash_equals(strtolower($lastSha), strtolower($tip))) {
            $message = __('Tip matches last deploy');
            $this->recordCheck($site, tip: $tip, outcome: 'unchanged', message: $message, skipReason: null);

            return $this->result(true, false, $tip, 'up_to_date', 'unchanged', $message);
        }

        $dispatched = $this->coordinator->deploy($site->fresh() ?? $site, SiteDeployment::TRIGGER_POLL);
        if ($dispatched) {
            $message = __('Queued deploy');
            $this->recordCheck($site, tip: $tip, outcome: 'deploy_queued', message: $message, skipReason: null);
            Log::info('Quick deploy poll dispatched', [
                'site_id' => $site->id,
                'tip' => $tip,
                'last_sha' => $lastSha,
            ]);

            return $this->result(true, true, $tip, null, 'deploy_queued', $message);
        }

        $message = __('Could not queue deploy');
        $this->recordCheck($site, tip: $tip, outcome: 'error', message: $message, skipReason: 'dispatch_refused');

        return $this->result(true, false, $tip, 'dispatch_refused', 'error', $message);
    }

    public function resolveTipSha(Site $site): ?string
    {
        return $this->resolveTipShaDetailed($site)['sha'];
    }

    /**
     * @return array{sha: string|null, error: string|null, http_status: int|null}
     */
    public function resolveTipShaDetailed(Site $site): array
    {
        $provider = $this->detectProviderKind((string) ($site->git_repository_url ?? ''));
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return ['sha' => null, 'error' => __('Unsupported Git provider'), 'http_status' => null];
        }

        $identity = $this->resolveIdentity($site, $provider);
        if ($identity === null) {
            return ['sha' => null, 'error' => __('No linked source-control account'), 'http_status' => null];
        }

        $ref = GitRemoteRepositoryRef::parse((string) $site->git_repository_url, $provider);
        if ($ref === null) {
            return ['sha' => null, 'error' => __('Could not parse repository URL'), 'http_status' => null];
        }

        $branch = trim((string) ($site->git_branch ?: 'main')) ?: 'main';

        return match ($provider) {
            'github' => $this->tipGithub($identity, $ref, $branch),
            'gitlab' => $this->tipGitlab($identity, $ref, $branch),
            default => $this->tipBitbucket($identity, $ref, $branch),
        };
    }

    private function lastSuccessfulSha(Site $site): ?string
    {
        $sha = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->whereNotNull('git_sha')
            ->where('git_sha', '!=', '')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->value('git_sha');

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    private function recordCheck(
        Site $site,
        string $tip,
        string $outcome,
        string $message,
        ?string $skipReason,
        ?int $httpStatus = null,
        ?string $errorKind = null,
    ): void {
        $at = now()->toIso8601String();
        $entry = [
            'at' => $at,
            'sha' => $tip !== '' ? $tip : null,
            'sha_short' => $tip !== '' ? Str::substr($tip, 0, 7) : null,
            'outcome' => $outcome,
            'message' => $message,
            'http_status' => $httpStatus,
            'error_kind' => $errorKind,
        ];

        $existing = $site->repositoryMeta()['poll_log'] ?? [];
        $log = is_array($existing) ? $existing : [];
        array_unshift($log, $entry);
        $log = array_values(array_slice($log, 0, self::POLL_LOG_MAX));

        $patch = [
            'poll_last_checked_at' => $at,
            'poll_last_skip_reason' => $skipReason,
            'poll_log' => $log,
        ];
        if ($tip !== '') {
            $patch['poll_last_tip_sha'] = $tip;
        }

        $site->mergeRepositoryMeta($patch);
        $site->save();
    }

    /**
     * @param  array{sha: string|null, error: string|null, http_status: int|null}  $resolved
     */
    private function tipUnresolvedMessage(array $resolved): string
    {
        $status = $resolved['http_status'] ?? null;
        if (in_array($status, [401, 403], true)) {
            return __('Source control denied access (HTTP :status)', ['status' => $status]);
        }

        $error = $resolved['error'] ?? null;
        if (is_string($error) && $error !== '') {
            if (str_contains(strtolower($error), 'no linked source-control')) {
                return __('No linked source-control account');
            }

            return __('Git API error: :err', ['err' => Str::limit($error, 120)]);
        }

        return __('Could not resolve branch tip');
    }

    /**
     * @param  array{sha: string|null, error: string|null, http_status: int|null}  $resolved
     */
    private function errorKindFromResolved(array $resolved): ?string
    {
        $status = $resolved['http_status'] ?? null;
        if (in_array($status, [401, 403], true)) {
            return 'auth';
        }

        $error = strtolower((string) ($resolved['error'] ?? ''));
        if ($error !== '' && str_contains($error, 'no linked source-control')) {
            return 'no_account';
        }

        return null;
    }

    private function resolveIdentity(Site $site, string $provider): ?GitIdentity
    {
        $id = (string) ($site->repositoryMeta()['git_source_control_account_id'] ?? '');
        if ($id !== '') {
            $identity = SocialAccount::query()->find($id) ?? GitProviderToken::query()->find($id);
            if ($identity instanceof GitIdentity && $identity->accessToken() !== '' && $identity->provider() === $provider) {
                return $identity;
            }
        }

        $ownerId = $site->user_id;
        if ($ownerId === null) {
            return null;
        }

        $oauth = SocialAccount::query()
            ->where('user_id', $ownerId)
            ->where('provider', $provider)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->first();
        if ($oauth instanceof GitIdentity) {
            return $oauth;
        }

        $pat = GitProviderToken::query()
            ->where('user_id', $ownerId)
            ->where('provider', $provider)
            ->orderBy('id')
            ->first();

        return ($pat instanceof GitIdentity && $pat->accessToken() !== '') ? $pat : null;
    }

    /**
     * @return array{sha: string|null, error: string|null, http_status: int|null}
     */
    private function tipGithub(GitIdentity $identity, GitRemoteRepositoryRef $ref, string $branch): array
    {
        if ($ref->owner === null || $ref->repo === null) {
            return ['sha' => null, 'error' => __('Invalid GitHub repository path'), 'http_status' => null];
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Dply (quick-deploy-poll)',
            'Accept' => 'application/vnd.github+json',
        ])
            ->withToken($identity->accessToken())
            ->acceptJson()
            ->timeout(15)
            ->get($identity->apiBaseUrl().'/repos/'.$ref->owner.'/'.$ref->repo.'/commits', [
                'sha' => $branch,
                'per_page' => 1,
            ]);

        if (! $response->successful()) {
            return ['sha' => null, 'error' => 'HTTP '.$response->status(), 'http_status' => $response->status()];
        }

        $sha = $response->json('0.sha');

        return is_string($sha) && $sha !== ''
            ? ['sha' => $sha, 'error' => null, 'http_status' => null]
            : ['sha' => null, 'error' => __('Empty commit list'), 'http_status' => null];
    }

    /**
     * @return array{sha: string|null, error: string|null, http_status: int|null}
     */
    private function tipGitlab(GitIdentity $identity, GitRemoteRepositoryRef $ref, string $branch): array
    {
        $path = $ref->gitlabProjectPath;
        if ($path === null || $path === '') {
            return ['sha' => null, 'error' => __('Invalid GitLab project path'), 'http_status' => null];
        }

        $response = Http::withToken($identity->accessToken())
            ->acceptJson()
            ->timeout(15)
            ->get($identity->apiBaseUrl().'/api/v4/projects/'.rawurlencode($path).'/repository/commits', [
                'ref_name' => $branch,
                'per_page' => 1,
            ]);

        if (! $response->successful()) {
            return ['sha' => null, 'error' => 'HTTP '.$response->status(), 'http_status' => $response->status()];
        }

        $sha = $response->json('0.id');

        return is_string($sha) && $sha !== ''
            ? ['sha' => $sha, 'error' => null, 'http_status' => null]
            : ['sha' => null, 'error' => __('Empty commit list'), 'http_status' => null];
    }

    /**
     * @return array{sha: string|null, error: string|null, http_status: int|null}
     */
    private function tipBitbucket(GitIdentity $identity, GitRemoteRepositoryRef $ref, string $branch): array
    {
        if ($ref->owner === null || $ref->repo === null) {
            return ['sha' => null, 'error' => __('Invalid Bitbucket repository path'), 'http_status' => null];
        }

        $response = Http::withToken($identity->accessToken())
            ->acceptJson()
            ->timeout(15)
            ->get($identity->apiBaseUrl().'/2.0/repositories/'.$ref->owner.'/'.$ref->repo.'/commits/'.$branch, [
                'pagelen' => 1,
            ]);

        if (! $response->successful()) {
            return ['sha' => null, 'error' => 'HTTP '.$response->status(), 'http_status' => $response->status()];
        }

        $sha = $response->json('values.0.hash') ?? $response->json('hash');

        return is_string($sha) && $sha !== ''
            ? ['sha' => $sha, 'error' => null, 'http_status' => null]
            : ['sha' => null, 'error' => __('Empty commit list'), 'http_status' => null];
    }

    private function detectProviderKind(string $url): string
    {
        $url = strtolower($url);

        return match (true) {
            str_contains($url, 'github.com') => 'github',
            str_contains($url, 'gitlab') => 'gitlab',
            str_contains($url, 'bitbucket.org') => 'bitbucket',
            default => 'custom',
        };
    }

    /**
     * @return array{checked: bool, dispatched: bool, tip: string|null, reason: string|null, outcome: string|null, message: string|null}
     */
    private function result(
        bool $checked,
        bool $dispatched,
        ?string $tip,
        ?string $reason,
        ?string $outcome = null,
        ?string $message = null,
    ): array {
        return [
            'checked' => $checked,
            'dispatched' => $dispatched,
            'tip' => $tip,
            'reason' => $reason,
            'outcome' => $outcome,
            'message' => $message,
        ];
    }
}
