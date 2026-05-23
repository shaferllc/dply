<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Edge\CreateEdgePreviewSite;
use App\Actions\Edge\DeployEdgeCommit;
use App\Actions\Edge\RedeployEdgeSite;
use App\Actions\Edge\RollbackEdgeDeployment;
use App\Jobs\TeardownEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Services\Edge\EdgeCustomDomainProvisioner;
use App\Services\Edge\EdgeGithubWebhookProvisioner;
use App\Services\Edge\EdgeHostMapPublisher;
use App\Services\Edge\EdgeRouter;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SiteGitCommitsFetcher;
use App\Services\SourceControl\SourceControlRepositoryReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Edge dashboard actions for Sites\Settings — redeploy, rollback,
 * preview teardown, custom domains. Mirrors {@see ManagesContainerSite}.
 */
trait ManagesEdgeSite
{
    public string $edge_domain_input = '';

    public string $edge_webhook_account_id = '';

    public string $edge_deploy_commit_sha = '';

    public bool $edge_deploy_ref_picker_open = false;

    public string $edge_deploy_ref_tab = 'commits';

    public string $edge_deploy_ref_search = '';

    public string $edge_deploy_ref_branch = '';

    /** @var list<array<string, mixed>> */
    public array $edge_deploy_ref_results = [];

    public ?string $edge_deploy_ref_error = null;

    public int $edge_releases_to_keep = 10;

    public string $edge_build_command = '';

    public string $edge_output_dir = 'dist';

    public bool $edge_spa_fallback = true;

    public bool $edge_deploy_on_push = true;

    public function mountEdgeWebhookAccount(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }

        $edge = $this->site->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];

        $this->edge_build_command = (string) ($build['command'] ?? 'npm ci && npm run build');
        $this->edge_output_dir = (string) ($build['output_dir'] ?? 'dist');
        $this->edge_spa_fallback = (bool) ($routing['spa_fallback'] ?? ($edge['spa_fallback'] ?? true));
        $this->edge_deploy_on_push = (bool) ($source['deploy_on_push'] ?? true);

        $webhook = is_array($edge['webhook'] ?? null) ? $edge['webhook'] : null;
        $accountId = is_array($webhook) ? (string) ($webhook['account_id'] ?? '') : '';
        if ($accountId !== '') {
            $this->edge_webhook_account_id = $accountId;
        }

        $configured = (int) ($this->site->releases_to_keep ?? 0);
        $this->edge_releases_to_keep = $configured > 0
            ? $configured
            : (int) config('edge.retention.default_keep', 10);
    }

    public function saveEdgeReleasesToKeep(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $value = (int) $this->edge_releases_to_keep;
        if ($value < 1 || $value > 50) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Releases to keep must be between 1 and 50.'));
            }

            return;
        }

        $this->site->update(['releases_to_keep' => $value]);
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Retention updated.'));
        }
    }

    public function saveEdgeBuildSettings(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'edge_build_command' => ['required', 'string', 'max:500'],
            'edge_output_dir' => ['required', 'string', 'max:200'],
            'edge_spa_fallback' => ['boolean'],
            'edge_deploy_on_push' => ['boolean'],
        ]);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];

        $previousSpaFallback = (bool) ($routing['spa_fallback'] ?? ($edge['spa_fallback'] ?? true));

        $build['command'] = trim((string) $validated['edge_build_command']);
        $build['output_dir'] = trim((string) $validated['edge_output_dir']);
        $source['deploy_on_push'] = (bool) $validated['edge_deploy_on_push'];
        $routing['spa_fallback'] = (bool) $validated['edge_spa_fallback'];

        $site->mergeEdgeMeta([
            'build' => $build,
            'source' => $source,
            'routing' => $routing,
        ]);
        $site->save();
        $this->site->refresh();

        if ($previousSpaFallback !== (bool) $validated['edge_spa_fallback']) {
            $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
            if (is_string($activeId) && $activeId !== '') {
                $deployment = EdgeDeployment::query()->find($activeId);
                if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                    app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
                }
            }
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Build settings saved. Changes apply on the next deploy.'));
        }
    }

    public function enableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        if ($this->edge_webhook_account_id === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Select a linked GitHub account first.'));
            }

            return;
        }

        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->edge_webhook_account_id)
            : null;
        if ($account === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('That GitHub account is no longer linked.'));
            }

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! ($result['ok'] ?? false)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError((string) ($result['message'] ?? __('Could not connect GitHub webhook.')));
            }

            return;
        }

        $this->site->refresh();
        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess((string) ($result['message'] ?? __('GitHub webhook connected.')));
        }
    }

    public function disableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $account = null;
        if ($this->edge_webhook_account_id !== '' && auth()->user() !== null) {
            $account = app(GitIdentityResolver::class)->forId(auth()->user(), $this->edge_webhook_account_id);
        }

        $provisioner->disable($this->site->fresh(), $account);
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('GitHub webhook disconnected.'));
        }
    }

    public function redeployEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RedeployEdgeSite)->handle($this->site);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Edge redeploy queued.'));
        }
    }

    public function deployEdgeCommit(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $sha = trim($this->edge_deploy_commit_sha);
        if ($sha === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Enter a commit SHA to deploy.'));
            }

            return;
        }

        try {
            (new DeployEdgeCommit)->handle($this->site, $sha);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->edge_deploy_commit_sha = '';

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Deploy started for that commit.'));
        }
    }

    public function openEdgeDeployRefPicker(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $source = is_array($this->site->edgeMeta()['source'] ?? null) ? $this->site->edgeMeta()['source'] : [];
        $this->edge_deploy_ref_branch = (string) ($source['branch'] ?? 'main');
        $this->edge_deploy_ref_picker_open = true;
        $this->refreshEdgeDeployRefs();
    }

    public function closeEdgeDeployRefPicker(): void
    {
        $this->edge_deploy_ref_picker_open = false;
    }

    public function setEdgeDeployRefTab(string $tab): void
    {
        if (! in_array($tab, ['commits', 'branches', 'tags'], true)) {
            return;
        }

        $this->edge_deploy_ref_tab = $tab;
        $this->refreshEdgeDeployRefs();
    }

    public function updatedEdgeDeployRefSearch(): void
    {
        if ($this->edge_deploy_ref_picker_open) {
            $this->refreshEdgeDeployRefs();
        }
    }

    public function updatedEdgeDeployRefBranch(): void
    {
        if ($this->edge_deploy_ref_picker_open && $this->edge_deploy_ref_tab === 'commits') {
            $this->refreshEdgeDeployRefs();
        }
    }

    public function selectEdgeDeployRef(string $sha): void
    {
        $sha = strtolower(trim($sha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            return;
        }

        $this->edge_deploy_commit_sha = $sha;
        $this->closeEdgeDeployRefPicker();
    }

    private function refreshEdgeDeployRefs(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->edge_deploy_ref_results = [];
            $this->edge_deploy_ref_error = __('Sign in to browse repository refs.');

            return;
        }

        $search = mb_strtolower(trim($this->edge_deploy_ref_search));

        if ($this->edge_deploy_ref_tab === 'branches') {
            $result = app(SourceControlRepositoryReader::class)->branches($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->edge_deploy_ref_results = [];
                $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load branches.'));

                return;
            }

            $this->edge_deploy_ref_error = null;
            $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
                collect($result['branches'] ?? [])
                    ->map(fn (array $branch): array => [
                        'kind' => 'branch',
                        'label' => (string) ($branch['name'] ?? ''),
                        'sha' => (string) ($branch['sha'] ?? ''),
                        'meta' => ($branch['is_default'] ?? false) ? __('Default branch') : null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        if ($this->edge_deploy_ref_tab === 'tags') {
            $result = app(SourceControlRepositoryReader::class)->tags($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->edge_deploy_ref_results = [];
                $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load tags.'));

                return;
            }

            $this->edge_deploy_ref_error = null;
            $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
                collect($result['tags'] ?? [])
                    ->map(fn (array $tag): array => [
                        'kind' => 'tag',
                        'label' => (string) ($tag['name'] ?? ''),
                        'sha' => (string) ($tag['sha'] ?? ''),
                        'meta' => null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        $branch = trim($this->edge_deploy_ref_branch) !== '' ? trim($this->edge_deploy_ref_branch) : null;
        $result = app(SiteGitCommitsFetcher::class)->fetch($this->site, $user, 40, $branch);
        if (! ($result['ok'] ?? false)) {
            $this->edge_deploy_ref_results = [];
            $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load commits.'));

            return;
        }

        $this->edge_deploy_ref_error = null;
        $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
            collect($result['commits'] ?? [])
                ->map(fn (array $commit): array => [
                    'kind' => 'commit',
                    'label' => (string) ($commit['short_sha'] ?? substr((string) ($commit['sha'] ?? ''), 0, 7)),
                    'sha' => (string) ($commit['sha'] ?? ''),
                    'meta' => Str::limit((string) ($commit['message'] ?? ''), 72),
                ])
                ->all(),
            $search,
            ['label', 'sha', 'meta'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function filterEdgeDeployRefs(array $rows, string $search, array $fields): array
    {
        if ($search === '') {
            return array_values(array_filter($rows, fn (array $row): bool => ($row['sha'] ?? '') !== ''));
        }

        return array_values(array_filter($rows, function (array $row) use ($search, $fields): bool {
            if (($row['sha'] ?? '') === '') {
                return false;
            }

            foreach ($fields as $field) {
                $value = mb_strtolower((string) ($row[$field] ?? ''));
                if ($value !== '' && str_contains($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function rollbackEdgeDeployment(string $deploymentId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RollbackEdgeDeployment)->handle($this->site, $deploymentId);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Rolled back — the selected deployment is now live.'));
        }
    }

    public function tearDownEdgePreview(string $previewSiteId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $this->site->id) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Preview not found or not a child of this site.'));
            }

            return;
        }

        TeardownEdgeSiteJob::dispatch($preview->id);

        if (method_exists($this, 'toastSuccess')) {
            $branch = (string) ($preview->edgeMeta()['preview_branch'] ?? '');
            $this->toastSuccess(__('Preview teardown queued for branch :branch.', ['branch' => $branch]));
        }
    }

    public function attachEdgeDomain(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim($this->edge_domain_input));
        $hostname = preg_replace('#^https?://#', '', (string) $hostname);
        $hostname = rtrim((string) $hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Hostname does not look valid.'));
            }

            return;
        }

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            $backend->attachDomain($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->edge_domain_input = '';
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain attached. Configure DNS, then verify when ready.'));
        }
    }

    public function verifyEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $entry = app(EdgeCustomDomainProvisioner::class)->verify($this->site->fresh(), $hostname);
        $this->site->refresh();

        $status = is_array($entry) ? (string) ($entry['dns_status'] ?? '') : '';
        if ($status === 'ready') {
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('DNS verified — :hostname is live on Edge.', ['hostname' => $hostname]));
            }

            return;
        }

        $error = is_array($entry) ? (string) ($entry['error'] ?? '') : '';
        if (method_exists($this, 'toastError')) {
            $this->toastError($error !== '' ? $error : __('DNS verification failed. Check your CNAME and try again.'));
        }
    }

    public function detachEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            app(EdgeCustomDomainProvisioner::class)->remove($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain removed.'));
        }
    }

    public function openEdgeTeardownModal(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);
        $this->dispatch('open-modal', 'edge-teardown-confirmation');
    }

    public function tearDownEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);

        TeardownEdgeSiteJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Edge site teardown queued.'));
        }
    }

    /**
     * @return Collection<int, EdgeDeployment>
     */
    public function edgeDeploymentHistory(int $limit = 10): Collection
    {
        return $this->site->edgeDeployments()->limit($limit)->get();
    }

    /**
     * @return Collection<int, Site>
     */
    public function edgePreviewSites(): Collection
    {
        return CreateEdgePreviewSite::listForParent($this->site);
    }
}
