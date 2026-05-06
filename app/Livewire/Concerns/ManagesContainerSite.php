<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\AttachEdgeDomainJob;
use App\Jobs\DetachEdgeDomainJob;
use App\Jobs\RedeployEdgeSiteJob;
use App\Jobs\TeardownEdgeSiteJob;
use App\Models\Site;
use App\Services\Edge\EdgeRouter;

/**
 * Methods bolted onto Sites\Settings (and any future container
 * dashboard surfaces) for triggering edge actions on a container
 * site. Lives in its own trait so the giant Settings.php class
 * stays focused on its existing PHP/Laravel/Node responsibilities.
 *
 * Assumes a public $site property of type Site on the host class.
 */
trait ManagesContainerSite
{
    public string $container_image_input = '';

    public string $container_domain_input = '';

    public string $container_env_file_input = '';

    public string $container_build_env_file_input = '';

    /**
     * Populated by fetchContainerLogs(); shape matches
     * EdgeBackend::latestDeploymentLogs return: { content?, url?, message? }.
     *
     * @var array<string, ?string>|null
     */
    public ?array $container_logs_result = null;

    /**
     * Populated by fetchContainerDeployments(); list of normalized
     * deployment rows: { id, phase, started_at, finished_at, cause }.
     *
     * @var list<array<string, ?string>>|null
     */
    public ?array $container_deployments_result = null;

    public function bootManagesContainerSite(): void
    {
        if ($this->container_image_input === '' && isset($this->site)) {
            $this->container_image_input = (string) ($this->site->container_image ?? '');
        }
        if ($this->container_env_file_input === '' && isset($this->site)) {
            $this->container_env_file_input = (string) ($this->site->env_file_content ?? '');
        }
        if ($this->container_build_env_file_input === '' && isset($this->site)) {
            $meta = is_array($this->site->meta) ? $this->site->meta : [];
            $this->container_build_env_file_input = (string) ($meta['container']['build_env_file_content'] ?? '');
        }
    }

    public function saveContainerEnvAndRedeploy(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);
        $this->validate([
            'container_env_file_input' => 'nullable|string|max:65535',
            'container_build_env_file_input' => 'nullable|string|max:65535',
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'build_env_file_content' => $this->container_build_env_file_input,
        ]);
        $this->site->update([
            'env_file_content' => $this->container_env_file_input,
            'meta' => $meta,
        ]);

        $backend = EdgeRouter::backendFor($this->site->fresh());
        $credential = EdgeRouter::credentialFor($this->site->fresh());
        if ($backend !== null && $credential !== null) {
            try {
                $backend->updateEnvVars($this->site->fresh(), $credential);
            } catch (\Throwable $e) {
                if (method_exists($this, 'toastError')) {
                    $this->toastError(__('Saved env vars locally, but pushing to backend failed: :err', ['err' => $e->getMessage()]));
                }

                return;
            }
        }

        RedeployEdgeSiteJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Env vars saved and redeploy queued. The backend will pick up the new values on the next roll.'));
        }
    }

    public function redeployContainer(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $newImage = trim($this->container_image_input);
        $changed = $newImage !== '' && $newImage !== (string) $this->site->container_image;

        RedeployEdgeSiteJob::dispatch($this->site->id, $changed ? $newImage : null);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess($changed
                ? __('Image updated and redeploy queued.')
                : __('Redeploy queued.'));
        }
    }

    public function tearDownContainer(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);

        TeardownEdgeSiteJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Tear-down queued. The container will be deleted on the backend shortly.'));
        }
    }

    /**
     * Tear down a preview deployment that's a child of the current
     * source-mode parent site. Authorisation goes through the parent
     * — if you can edit the parent, you can manage its previews.
     */
    public function tearDownContainerPreview(string $previewSiteId): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->meta['container']['preview_parent_site_id'] ?? null) !== $this->site->id) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Preview not found or not a child of this site.'));
            }

            return;
        }

        TeardownEdgeSiteJob::dispatch($preview->id);

        if (method_exists($this, 'toastSuccess')) {
            $branch = (string) ($preview->meta['container']['preview_branch'] ?? '');
            $this->toastSuccess(__('Preview teardown queued for branch :branch.', ['branch' => $branch]));
        }
    }

    public function attachContainerDomain(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim($this->container_domain_input));
        $hostname = preg_replace('#^https?://#', '', (string) $hostname);
        $hostname = rtrim((string) $hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Hostname does not look valid.'));
            }

            return;
        }

        AttachEdgeDomainJob::dispatch($this->site->id, $hostname);
        $this->container_domain_input = '';

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Domain attach queued. DNS validation records will appear here shortly.'));
        }
    }

    public function detachContainerDomain(string $hostname): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        DetachEdgeDomainJob::dispatch($this->site->id, $hostname);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Domain detach queued.'));
        }
    }

    public function fetchContainerLogs(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('view', $this->site);

        $backend = EdgeRouter::backendFor($this->site);
        $credential = EdgeRouter::credentialFor($this->site);
        if ($backend === null || $credential === null) {
            $this->container_logs_result = [
                'content' => null,
                'url' => null,
                'message' => __('No backend or credential resolvable for this site.'),
            ];

            return;
        }

        try {
            $this->container_logs_result = $backend->latestDeploymentLogs($this->site, $credential);
        } catch (\Throwable $e) {
            $this->container_logs_result = [
                'content' => null,
                'url' => null,
                'message' => __('Failed to fetch logs: :err', ['err' => $e->getMessage()]),
            ];
        }
    }

    public function fetchContainerDeployments(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('view', $this->site);

        $backend = EdgeRouter::backendFor($this->site);
        $credential = EdgeRouter::credentialFor($this->site);
        if ($backend === null || $credential === null) {
            $this->container_deployments_result = [];

            return;
        }

        try {
            $this->container_deployments_result = $backend->recentDeployments($this->site, $credential, 10);
        } catch (\Throwable $e) {
            $this->container_deployments_result = [];
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Failed to fetch deployments: :err', ['err' => $e->getMessage()]));
            }
        }
    }

    public function rollbackContainerImage(string $image): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $image = trim($image);
        if ($image === '' || $image === $this->site->container_image) {
            if (method_exists($this, 'toastWarning')) {
                $this->toastWarning(__('Already on that image — nothing to roll back to.'));
            }

            return;
        }

        RedeployEdgeSiteJob::dispatch($this->site->id, $image);
        $this->container_image_input = $image;

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Rollback to :image queued.', ['image' => $image]));
        }
    }
}
