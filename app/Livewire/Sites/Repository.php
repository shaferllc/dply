<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SiteGitCommitsFetcher;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Services\SourceControl\SourceControlRepositoryReader;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * DEPLOY > Repository — read + manage the connected git repository for a
 * serverless workspace. The legacy `?section=repository` settings tab
 * was a config form; serverless operators don't ssh into the host, so
 * the page is recast as a small repo dashboard: overview (commits +
 * README), file browser, branch picker, and a connection tab that
 * subsumes the old form.
 *
 * Reads go through {@see SourceControlRepositoryReader} (cached). The
 * Connection tab's actions reuse existing services
 * ({@see SourceControlRepositoryBrowser} for repo lists,
 * {@see RepositoryWebhookProvisioner} for webhooks) rather than wiring
 * up new code paths.
 */
#[Layout('layouts.app')]
class Repository extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    /** Path being browsed under the Files tab. Empty string = repo root. */
    #[Url(as: 'path', except: '')]
    public string $filesPath = '';

    /** File the operator clicked into in the Files tab. */
    public string $filesOpenFile = '';

    /** Override which ref the Files/Overview tabs read from — defaults to `Site::$git_branch`. */
    #[Url(as: 'ref', except: '')]
    public string $branchOverride = '';

    /** Search box on the Branches tab. */
    public string $branchSearch = '';

    /** Filter box on the Commits tab (matches message / author / sha). */
    #[Url(as: 'q', except: '')]
    public string $commitFilter = '';

    /** Connection-tab form mirrors of the equivalent fields on the Site. */
    public string $connectionRepositoryUrl = '';

    public string $connectionBranch = '';

    public string $connectionAccountId = '';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;

        $this->connectionRepositoryUrl = (string) ($site->git_repository_url ?? '');
        $this->connectionBranch = (string) ($site->git_branch ?? '');
        $this->connectionAccountId = (string) ($site->repositoryMeta()['git_source_control_account_id'] ?? '');
    }

    #[On('source-control-linked')]
    public function onSourceControlLinked(): void
    {
        // Re-render loads fresh accounts in renderConnectionPayload().
    }

    /* ──────────── Files navigation ──────────── */

    public function navigateToPath(string $path): void
    {
        $this->filesPath = trim($path, '/');
        $this->filesOpenFile = '';
    }

    public function openFile(string $path): void
    {
        $this->filesOpenFile = trim($path, '/');
    }

    public function closeFile(): void
    {
        $this->filesOpenFile = '';
    }

    /* ──────────── Branches ──────────── */

    public function switchBranch(string $branch, SourceControlRepositoryReader $reader): void
    {
        Gate::authorize('update', $this->site);

        $branch = trim($branch);
        if ($branch === '') {
            $this->toastError(__('Pick a branch first.'));

            return;
        }

        $this->site->forceFill(['git_branch' => $branch])->save();
        $reader->invalidate($this->site);
        $this->connectionBranch = $branch;
        $this->branchOverride = '';
        $this->toastSuccess(__('Deploy branch set to :branch.', ['branch' => $branch]));
    }

    /* ──────────── Repository switch ──────────── */

    public function switchRepository(string $repositoryUrl, ?string $defaultBranch, SourceControlRepositoryReader $reader): void
    {
        Gate::authorize('update', $this->site);

        $url = trim($repositoryUrl);
        if ($url === '') {
            $this->toastError(__('Repository URL is empty.'));

            return;
        }

        $branch = trim((string) $defaultBranch);
        if ($branch === '') {
            $branch = 'main';
        }

        $this->site->forceFill([
            'git_repository_url' => $url,
            'git_branch' => $branch,
        ])->save();
        $reader->invalidate($this->site);

        $this->connectionRepositoryUrl = $url;
        $this->connectionBranch = $branch;
        $this->filesPath = '';
        $this->filesOpenFile = '';
        $this->branchOverride = '';

        $this->toastSuccess(__('Repository switched to :url.', ['url' => $url]));
    }

    /* ──────────── Connection tab actions ──────────── */

    public function saveConnection(SourceControlRepositoryReader $reader): void
    {
        Gate::authorize('update', $this->site);

        $this->validate([
            'connectionRepositoryUrl' => 'required|string|max:500',
            'connectionBranch' => 'required|string|max:120',
            'connectionAccountId' => 'nullable|string|max:26',
        ]);

        $url = trim($this->connectionRepositoryUrl);
        $branch = trim($this->connectionBranch) !== '' ? trim($this->connectionBranch) : 'main';

        $this->site->mergeRepositoryMeta([
            'git_source_control_account_id' => $this->connectionAccountId !== '' ? $this->connectionAccountId : null,
            'git_provider_kind' => $this->detectProviderKind($url),
        ]);

        $this->site->fill([
            'git_repository_url' => $url,
            'git_branch' => $branch,
        ])->save();
        $reader->invalidate($this->site);

        $this->toastSuccess(__('Repository connection saved.'));
    }

    public function enableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        if ($this->connectionAccountId === '') {
            $this->toastError(__('Select a linked source-control account before enabling the provider hook.'));

            return;
        }

        $account = app(GitIdentityResolver::class)->forId(auth()->user(), $this->connectionAccountId);
        if ($account === null) {
            $this->toastError(__('That source-control account is no longer linked.'));

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! ($result['ok'] ?? false)) {
            $this->toastError((string) ($result['message'] ?? __('Could not enable quick deploy.')));

            return;
        }

        $this->site->refresh();
        $this->toastSuccess((string) ($result['message'] ?? __('Quick deploy enabled.')));
    }

    public function disableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $provisioner->disable($this->site->fresh());
        $this->site->refresh();
        $this->toastSuccess(__('Quick deploy disabled.'));
    }

    public function regenerateWebhookSecret(RepositoryWebhookProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $plain = Str::random(48);
        $this->site->webhook_secret = $plain;
        $this->site->save();
        $provisioner->syncProviderHookSecret($this->site->fresh());
        $this->toastSuccess(__('Webhook secret rotated.'));
    }

    /* ──────────── Render ──────────── */

    public function render(): View
    {
        $user = auth()->user();
        $reader = app(SourceControlRepositoryReader::class);
        $commitsFetcher = app(SiteGitCommitsFetcher::class);
        $browser = app(SourceControlRepositoryBrowser::class);

        $branchInUse = $this->branchOverride !== ''
            ? $this->branchOverride
            : (string) ($this->site->git_branch ?: 'main');

        $payload = [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => __('App'),
            'resourcePlural' => __('apps'),
            'section' => 'repository',
            'laravel_tab' => 'commands',
            'routingTab' => 'domains',
            'branchInUse' => $branchInUse,
            'currentBranch' => (string) ($this->site->git_branch ?: 'main'),
            'currentRepositoryUrl' => (string) ($this->site->git_repository_url ?: ''),
            'providerKind' => (string) ($this->site->repositoryMeta()['git_provider_kind'] ?? ''),
        ];

        if ($payload['currentRepositoryUrl'] === '') {
            return view('livewire.sites.repository', $payload + $this->renderConnectionPayload($browser, $user));
        }

        return view('livewire.sites.repository', match ($this->tab) {
            'files' => $payload + $this->renderFilesPayload($reader, $user, $branchInUse),
            'branches' => $payload + $this->renderBranchesPayload($reader, $user),
            'commits' => $payload + $this->renderCommitsPayload($commitsFetcher, $user, $branchInUse),
            'connection' => $payload + $this->renderConnectionPayload($browser, $user),
            default => $payload + $this->renderOverviewPayload($reader, $commitsFetcher, $user, $branchInUse),
        });
    }

    /**
     * Full commit history for the Commits tab (merged in from the former
     * standalone Commits page). Server-side filters the fetched window by
     * message / author / sha and flags the last successfully-deployed sha.
     *
     * @return array{commitsResult: array<string, mixed>, commitsFiltered: list<array<string, mixed>>, lastDeployedSha: ?string}
     */
    private function renderCommitsPayload(SiteGitCommitsFetcher $fetcher, $user, string $branch): array
    {
        $result = $fetcher->fetch($this->site, $user, 40, $branch);

        $commits = $result['commits'];
        $filter = trim($this->commitFilter);
        if ($filter !== '') {
            $needle = mb_strtolower($filter);
            $commits = array_values(array_filter($commits, function (array $c) use ($needle): bool {
                return str_contains(mb_strtolower((string) $c['message']), $needle)
                    || str_contains(mb_strtolower((string) $c['author_name']), $needle)
                    || str_contains(mb_strtolower((string) $c['sha']), $needle)
                    || str_contains(mb_strtolower((string) $c['short_sha']), $needle);
            }));
        }

        $lastDeploy = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->whereNotNull('git_sha')
            ->orderByDesc('finished_at')
            ->first();

        return [
            'commitsResult' => $result,
            'commitsFiltered' => $commits,
            'lastDeployedSha' => $lastDeploy?->git_sha,
        ];
    }

    private function renderOverviewPayload(SourceControlRepositoryReader $reader, SiteGitCommitsFetcher $fetcher, $user, string $branch): array
    {
        $commits = $fetcher->fetch($this->site, $user, 5, $branch);
        $readme = $user !== null ? $reader->readme($this->site, $user, $branch) : null;

        return [
            'overviewCommits' => $commits,
            'overviewReadme' => $readme,
        ];
    }

    private function renderFilesPayload(SourceControlRepositoryReader $reader, $user, string $branch): array
    {
        if ($user === null) {
            return ['filesTree' => ['ok' => false, 'entries' => [], 'error' => __('Sign in to browse files.'), 'provider' => null, 'path' => $this->filesPath, 'branch' => $branch], 'filesView' => null];
        }

        $tree = $reader->tree($this->site, $user, $branch, $this->filesPath);
        $fileView = null;
        if ($this->filesOpenFile !== '') {
            $fileView = $reader->file($this->site, $user, $branch, $this->filesOpenFile);
        }

        return [
            'filesTree' => $tree,
            'filesView' => $fileView,
            'filesBreadcrumb' => $this->buildBreadcrumb($this->filesPath),
        ];
    }

    private function renderBranchesPayload(SourceControlRepositoryReader $reader, $user): array
    {
        if ($user === null) {
            return ['branchesResult' => ['ok' => false, 'branches' => [], 'error' => __('Sign in to list branches.'), 'provider' => null], 'branchesFiltered' => []];
        }

        $result = $reader->branches($this->site, $user);
        $filtered = $result['branches'];
        if ($this->branchSearch !== '') {
            $needle = strtolower($this->branchSearch);
            $filtered = array_values(array_filter($result['branches'], fn (array $b) => str_contains(strtolower($b['name']), $needle)));
        }

        return [
            'branchesResult' => $result,
            'branchesFiltered' => $filtered,
        ];
    }

    private function renderConnectionPayload(SourceControlRepositoryBrowser $browser, $user): array
    {
        $accounts = $user !== null ? $browser->accountsForUser($user) : [];
        $repositories = [];
        if ($user !== null && $this->connectionAccountId !== '') {
            $account = app(GitIdentityResolver::class)->forId($user, $this->connectionAccountId);
            if ($account !== null) {
                $repositories = $browser->repositoriesForAccount($account);
            }
        }

        return [
            'connectionAccounts' => $accounts,
            'connectionRepositories' => $repositories,
            'connectionQuickDeploy' => (bool) ($this->site->repositoryMeta()['quick_deploy_enabled'] ?? ($this->site->meta['quick_deploy_enabled'] ?? false)),
            'connectionDeployHookUrl' => method_exists($this->site, 'deployHookUrl') ? $this->site->deployHookUrl() : null,
        ];
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function buildBreadcrumb(string $path): array
    {
        $crumbs = [['name' => __('root'), 'path' => '']];
        $accum = '';
        foreach (array_filter(explode('/', $path)) as $segment) {
            $accum = $accum === '' ? $segment : $accum.'/'.$segment;
            $crumbs[] = ['name' => $segment, 'path' => $accum];
        }

        return $crumbs;
    }

    private function detectProviderKind(string $url): string
    {
        $url = strtolower($url);
        if (str_contains($url, 'github.com')) {
            return 'github';
        }
        if (str_contains($url, 'gitlab')) {
            return 'gitlab';
        }
        if (str_contains($url, 'bitbucket.org')) {
            return 'bitbucket';
        }

        return 'custom';
    }
}
