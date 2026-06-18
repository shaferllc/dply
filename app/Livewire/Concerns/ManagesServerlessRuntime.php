<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Site;
use Illuminate\Validation\Rule;

/**
 * Serverless function resource-limit editing for the Runtime tab.
 *
 * Memory / timeout / concurrency map onto the OpenWhisk action `limits`
 * block DigitalOcean Functions runs on. They are persisted to
 * meta.serverless.limits and pushed to the action by the deployer on the
 * next deploy — there is no live action-update path, so the UI tells the
 * operator when saved limits are pending a redeploy.
 *
 * The host component (Sites\Show and its subclasses) provides $site,
 * authorize(), validate(), and the toast helpers.
 */
trait ManagesServerlessRuntime
{
    public int $serverless_memory = Site::SERVERLESS_DEFAULT_MEMORY_MB;

    public int $serverless_timeout_ms = Site::SERVERLESS_DEFAULT_TIMEOUT_MS;

    public int $serverless_concurrency = Site::SERVERLESS_DEFAULT_CONCURRENCY;

    /**
     * Hydrate the form fields from the site's stored limits. Called from
     * Show::syncFormFromSite() so the Runtime tab opens pre-filled.
     */
    public function syncServerlessRuntimeFromSite(): void
    {
        $limits = $this->site->serverlessLimits();
        $this->serverless_memory = $limits['memory'];
        $this->serverless_timeout_ms = $limits['timeout'];
        $this->serverless_concurrency = $limits['concurrency'];
    }

    public function saveServerlessRuntime(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->usesFunctionsRuntime()) {
            $this->toastError(__('Resource limits apply to serverless functions only.'));

            return;
        }

        $this->validate([
            'serverless_memory' => ['required', 'integer', Rule::in(Site::SERVERLESS_MEMORY_OPTIONS_MB)],
            'serverless_timeout_ms' => ['required', 'integer', 'min:'.Site::SERVERLESS_MIN_TIMEOUT_MS, 'max:'.Site::SERVERLESS_MAX_TIMEOUT_MS],
            'serverless_concurrency' => ['required', 'integer', 'min:1', 'max:'.Site::SERVERLESS_MAX_CONCURRENCY],
        ], [], [
            'serverless_memory' => __('memory'),
            'serverless_timeout_ms' => __('timeout'),
            'serverless_concurrency' => __('concurrency'),
        ]);

        $meta = $this->site->meta;
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['limits'] = [
            'memory' => $this->serverless_memory,
            'timeout' => $this->serverless_timeout_ms,
            'concurrency' => $this->serverless_concurrency,
        ];
        $meta['serverless'] = $serverless;

        $this->site->forceFill(['meta' => $meta])->save();
        $this->site->setAttribute('meta', $meta);

        $this->toastSuccess(__('Resource limits saved — they apply on the next deploy.'));
    }
}
