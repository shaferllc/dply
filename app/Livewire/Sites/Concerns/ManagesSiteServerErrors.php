<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Support\Sites\SiteManagedErrorPageSupport;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteServerErrors
{
    /** True when this site is passing raw 5xx through instead of the branded page. */
    public function serverErrorsExposed(): bool
    {
        return SiteManagedErrorPageSupport::serverErrorsExposed($this->site);
    }

    /**
     * Let the app render its own error pages: stop intercepting 5xx with the
     * branded "temporarily unavailable" page so the real error shows — the app's
     * own 500/503, the framework debug page when APP_DEBUG is on, or the
     * webserver's own 502/504 when the upstream is down. This is the platform
     * default; calling it pins the site to pass-through even if the default
     * changes. Re-applies the managed webserver config so it takes effect.
     */
    public function exposeServerErrors(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta[SiteManagedErrorPageSupport::META_EXPOSE_FLAG] = true;
        $this->site->forceFill(['meta' => $meta])->save();
        $this->finalizeRoutingMutation(
            __('This site now passes the real error through — visitors and you see the app\'s own 5xx page.'),
            __('Applying — letting the app handle its own errors …'),
        );
    }

    /** Pin this site to the branded managed error page (intercept raw 5xx). */
    public function hideServerErrors(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta[SiteManagedErrorPageSupport::META_EXPOSE_FLAG] = false;
        $this->site->forceFill(['meta' => $meta])->save();
        $this->finalizeRoutingMutation(
            __('Branded error page enabled — visitors see dply\'s page (with a reference id) instead of the raw 5xx.'),
            __('Applying branded error page …'),
        );
    }
}
