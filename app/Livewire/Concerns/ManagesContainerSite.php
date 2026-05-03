<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\RedeployEdgeSiteJob;
use App\Jobs\TeardownEdgeSiteJob;

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

    public function bootManagesContainerSite(): void
    {
        if ($this->container_image_input === '' && isset($this->site)) {
            $this->container_image_input = (string) ($this->site->container_image ?? '');
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
}
