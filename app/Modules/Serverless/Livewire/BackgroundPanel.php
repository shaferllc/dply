<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Toggle background processing for a serverless function — the Laravel
 * scheduler and queue worker.
 *
 * When enabled, dply's own scheduler ({@see App\Modules\Serverless\Console\ServerlessTickCommand})
 * invokes the function every minute in command mode. DigitalOcean Functions
 * has no long-running process, so dply stands in as the cron.
 */
class BackgroundPanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    private function site(): Site
    {
        return Site::findOrFail($this->siteId);
    }

    public function toggle(): void
    {
        $enabled = $this->flip('background_enabled');

        $this->toastSuccess($enabled
            ? __('Background processing enabled — the scheduler and queue worker run every minute.')
            : __('Background processing disabled.'));
    }

    public function toggleKeepWarm(): void
    {
        $enabled = $this->flip('keep_warm');

        $this->toastSuccess($enabled
            ? __('Keep-warm enabled — dply pings the function every minute to cut cold starts.')
            : __('Keep-warm disabled.'));
    }

    private function flip(string $key): bool
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $value = ! ($serverless[$key] ?? false);
        $serverless[$key] = $value;
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        return $value;
    }

    public function render(): View
    {
        $site = $this->site();
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];

        return view('livewire.serverless.background-panel', [
            'enabled' => (bool) ($serverless['background_enabled'] ?? false),
            'keepWarm' => (bool) ($serverless['keep_warm'] ?? false),
            'deployed' => trim((string) ($serverless['action_url'] ?? '')) !== '',
        ]);
    }
}
