<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteLifecycleActions
{
    /** Optional message shown on the public suspended page; stored in meta `suspended_message` (VM sites only). */
    public string $settings_suspended_message = '';

    public function deleteSite(): mixed
    {
        $this->authorize('delete', $this->site);
        $organization = $this->site->server?->organization;
        $server = $this->site->server;
        $siteName = $this->site->name;
        $snapshot = [
            'name' => $siteName,
            'slug' => $this->site->slug,
            'server_id' => (string) $this->site->server_id,
            'type' => $this->site->type instanceof \BackedEnum ? $this->site->type->value : (string) $this->site->type,
            'runtime' => $this->site->runtime,
            'git_repository_url' => $this->site->git_repository_url,
        ];
        $this->site->delete();

        if ($organization) {
            audit_log(
                $organization,
                auth()->user(),
                'site.deleted',
                $this->site,
                $snapshot,
                null,
            );
        }

        // Hard redirect (no navigate: true) — Livewire's SPA-style soft
        // navigation re-hydrates the current component before the redirect
        // URL takes over, and the re-hydration tries to look up the just-
        // deleted Site → 404. A plain HTTP redirect avoids that round trip.
        session()->flash('success', __('Site :name was removed.', ['name' => $siteName]));

        // Back to the server's own sites list (not the all-servers index).
        return $server !== null
            ? redirect()->route('servers.sites', ['server' => $server->id])
            : redirect()->route('servers.index');
    }

    public function confirmSuspendSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Site suspension requires managed web server configuration on this host.'));

            return;
        }

        $this->openConfirmActionModal(
            'suspendSite',
            [],
            __('Suspend site'),
            __('Visitors will see a suspended page instead of your application until you resume. SSL and domains are unchanged.'),
            __('Suspend site'),
            true,
        );
    }

    /**
     * Suspension only swaps managed HTTP vhost config; deploy hooks and deployments are unchanged (MVP).
     */
    public function suspendSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Site suspension is not available for this runtime.'));

            return;
        }

        $this->validate([
            'settings_suspended_message' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $message = trim($this->settings_suspended_message);
        if ($message !== '') {
            $meta['suspended_message'] = $message;
        } else {
            unset($meta['suspended_message']);
        }

        $this->site->update([
            'suspended_at' => now(),
            'suspended_reason' => null,
            'meta' => $meta,
        ]);
        $this->site->refresh();
        $this->syncFormFromSite();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.suspended', $this->site, null, [
                'message' => $message !== '' ? $message : null,
            ]);
        }

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess(__('Site suspended. Webserver config queued.'));
    }

    public function resumeSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Resuming requires managed web server configuration on this host.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['suspended_message']);

        $this->site->suspended_at = null;
        $this->site->suspended_reason = null;
        $this->site->meta = $meta;
        $this->site->save();
        $this->site->refresh();
        $this->settings_suspended_message = '';

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.resumed', $this->site, null, null);
        }

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess(__('Site resumed. Webserver config queued.'));
    }
}
