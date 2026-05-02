<?php

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\SourceControl\SiteGitCommitsFetcher;
use App\Support\SiteSettingsSidebar;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Commits extends Component
{
    public Server $server;

    public Site $site;

    public string $branch = '';

    public string $filter = '';

    /** @var list<array{sha: string, short_sha: string, message: string, author_name: string, author_email: string|null, committed_at: string|null, html_url: string|null}> */
    public array $commits = [];

    public ?string $fetchError = null;

    public ?string $remoteLabel = null;

    public ?string $provider = null;

    public ?string $lastDeployedSha = null;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
        $this->branch = (string) ($site->git_branch ?: 'main');

        $lastDeploy = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->whereNotNull('git_sha')
            ->orderByDesc('finished_at')
            ->first();
        $this->lastDeployedSha = $lastDeploy?->git_sha;

        $this->refreshCommits(app(SiteGitCommitsFetcher::class));
    }

    public function refreshCommits(SiteGitCommitsFetcher $fetcher): void
    {
        Gate::authorize('view', $this->site);

        $result = $fetcher->fetch($this->site->fresh(), auth()->user(), 40, $this->branch !== '' ? $this->branch : null);

        $this->commits = $result['commits'];
        $this->fetchError = $result['error'];
        $this->remoteLabel = $result['remote_label'];
        $this->provider = $result['provider'];
        if ($result['branch'] !== '' && $this->branch === '') {
            $this->branch = $result['branch'];
        }
    }

    /**
     * @return list<array{sha: string, short_sha: string, message: string, author_name: string, author_email: string|null, committed_at: string|null, html_url: string|null}>
     */
    public function getFilteredCommitsProperty(): array
    {
        $q = trim($this->filter);
        if ($q === '') {
            return $this->commits;
        }

        $lower = mb_strtolower($q);

        return array_values(array_filter(
            $this->commits,
            function (array $c) use ($lower): bool {
                return str_contains(mb_strtolower($c['message']), $lower)
                    || str_contains(mb_strtolower($c['author_name']), $lower)
                    || str_contains(mb_strtolower($c['sha']), $lower)
                    || str_contains(mb_strtolower($c['short_sha']), $lower);
            }
        ));
    }

    public function relativeTime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->diffForHumans();
        } catch (\Throwable) {
            return null;
        }
    }

    public function render(): View
    {
        $settingsSidebarItems = SiteSettingsSidebar::items($this->site, $this->server);
        $runtimeMode = $this->site->runtimeTargetMode();
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $routingTab = 'domains';
        $laravel_tab = 'commands';
        $section = 'commits';
        $runtimeTarget = $this->site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];

        return view('livewire.sites.commits', [
            'settingsSidebarItems' => $settingsSidebarItems,
            'resourceNoun' => $resourceNoun,
            'resourcePlural' => $resourcePlural,
            'routingTab' => $routingTab,
            'laravel_tab' => $laravel_tab,
            'section' => $section,
            'runtimePublication' => $runtimePublication,
            'filteredCommits' => $this->filteredCommits,
        ]);
    }
}
