<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Support\Servers\CacheServiceAuth;
use App\Support\Servers\CacheServiceNetworkExposure;
use App\Support\Servers\CacheServicePort;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheSecurity
{
    /** Form input for setting the AUTH password on the redis-family engine of the current tab. */
    public string $new_auth_password = '';

    /** Form input for changing the listen port of the active instance on the current tab. */
    public ?int $new_port = null;

    /** Form input for the network-exposure flow's source CIDR (e.g. "10.0.0.0/8"). */
    public string $expose_source_cidr = '';

    public function generateAuthPassword(): void
    {
        $this->new_auth_password = Str::password(32, symbols: false);
    }

    public function setAuthPassword(CacheServiceAuth $auth, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to set its AUTH password.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine has no native AUTH password.', ['engine' => $row->engine, 'name' => $row->name]));

            return;
        }

        $this->validate([
            'new_auth_password' => ['required', 'string', 'min:12', 'max:256', 'regex:/^[\x21-\x7E]+$/'],
        ], [], [
            'new_auth_password' => __('AUTH password'),
        ]);

        try {
            $newAuth = $this->new_auth_password;
            $this->runConsoleAction(
                $row,
                'cache_set_auth',
                __('Set AUTH password on :engine on :host', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($auth, $row, $newAuth, $audits): void {
                    $emit->step('cache', sprintf('Setting requirepass on %s', $row->engine));
                    $auth->setRequirePass($row->server, $row, $newAuth);
                    $emit->success('cache', 'AUTH password active.');

                    $row->update(['auth_password' => $newAuth]);
                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_AUTH_SET,
                        ['engine' => $row->engine, 'name' => $row->name],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->new_auth_password = '';
            $this->toastSuccess(__('AUTH password set on :engine.', ['engine' => $row->engine, 'name' => $row->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function clearAuthPassword(CacheServiceAuth $auth, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to clear its AUTH password.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine has no native AUTH password.', ['engine' => $row->engine, 'name' => $row->name]));

            return;
        }

        try {
            $this->runConsoleAction(
                $row,
                'cache_clear_auth',
                __('Clear AUTH password on :engine on :host', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($auth, $row, $audits): void {
                    $emit->step('cache', sprintf('Clearing requirepass on %s', $row->engine));
                    $auth->clearRequirePass($row->server, $row);
                    $emit->success('cache', 'AUTH password cleared.');

                    $row->update(['auth_password' => null]);
                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_AUTH_CLEARED,
                        ['engine' => $row->engine, 'name' => $row->name],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->toastSuccess(__('Cleared AUTH password on :engine.', ['engine' => $row->engine, 'name' => $row->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Change the listen port for the active instance on the current engine tab. Validates the
     * port range, rejects collisions with other cache services on this server (the
     * `unique(server_id, port)` constraint would otherwise blow up at the DB layer with an
     * ugly error), and delegates the on-server work to {@see CacheServicePort} which handles
     * config rewrite + restart + verify + revert. The DB row is updated only after the SSH
     * verify succeeds, so a failed port change leaves the row pointing at the old port.
     */
    public function changeCachePort(CacheServicePort $portChanger, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to change its port.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        $this->validate([
            'new_port' => ['required', 'integer', 'min:1024', 'max:65535'],
        ], [], [
            'new_port' => __('Port'),
        ]);

        $newPort = (int) $this->new_port;

        if ($newPort === $row->port) {
            $this->toastError(__('That is already the current port.'));

            return;
        }

        $collision = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('port', $newPort)
            ->where('id', '!=', $row->id)
            ->first();
        if ($collision !== null) {
            $this->toastError(__('Port :port is already used by :other on this server.', [
                'port' => $newPort,
                'other' => $collision->engine.' '.$collision->name,
            ]));

            return;
        }

        $oldPort = $row->port;

        try {
            $this->runConsoleAction(
                $row,
                'cache_change_port',
                __('Change :engine port :old → :new on :host', [
                    'engine' => $row->engine, 'old' => $oldPort, 'new' => $newPort, 'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($portChanger, $row, $newPort, $oldPort, $audits): void {
                    $emit->step('cache', sprintf('Rewriting %s config to listen on :%d', $row->engine, $newPort));
                    $portChanger->changePort($row->server, $row, $newPort);
                    $emit->success('cache', sprintf('%s now listening on :%d', $row->engine, $newPort));

                    $row->update(['port' => $newPort]);
                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_PORT_CHANGED,
                        ['engine' => $row->engine, 'name' => $row->name, 'old_port' => $oldPort, 'new_port' => $newPort],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->new_port = null;
            $this->toastSuccess(__(':engine moved to port :port.', ['engine' => $row->engine, 'port' => $newPort]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Expose this cache instance to other servers in a network: rewrites the engine's bind to
     * 0.0.0.0, creates a panel firewall rule scoped to the source CIDR, and dispatches the
     * firewall apply. Refuses to expose Redis-family without an AUTH password — exposing an
     * un-authenticated cache to a network is the kind of foot-gun this dialog should prevent
     * even if the source CIDR is restrictive.
     */
    public function exposeCacheToNetwork(CacheServiceNetworkExposure $exposure, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to expose its instance.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Network exposure is currently only supported for Redis-family engines (Redis, Valkey, KeyDB).'));

            return;
        }

        if (empty($row->auth_password)) {
            $this->toastError(__('Set an AUTH password first — exposing an un-authenticated cache to the network is too risky to allow from this dialog.'));

            return;
        }

        $this->validate([
            'expose_source_cidr' => ['required', 'string', 'max:64'],
        ], [], [
            'expose_source_cidr' => __('Source CIDR'),
        ]);

        try {
            $cidr = $this->expose_source_cidr;
            $this->runConsoleAction(
                $row,
                'cache_expose',
                __('Expose :engine on :host to :cidr', [
                    'engine' => $row->engine, 'host' => $this->server->name, 'cidr' => $cidr,
                ]),
                function (ConsoleEmitter $emit) use ($exposure, $row, $cidr, $audits): void {
                    $emit->step('cache', sprintf('Rewriting bind to 0.0.0.0; firewall rule for %s', $cidr));
                    $exposure->expose($row->server, $row, $cidr, auth()->id());
                    $emit->success('cache', 'Exposed; firewall apply queued.');

                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
                        ['engine' => $row->engine, 'name' => $row->name, 'change' => 'exposed', 'source' => $cidr],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->expose_source_cidr = '';
            $this->toastSuccess(__(':engine exposed on :port from :cidr — firewall apply queued.', [
                'engine' => $row->engine,
                'port' => $row->port,
                'cidr' => $cidr,
            ]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Reverse {@see exposeCacheToNetwork()} — bind back to 127.0.0.1, remove the firewall
     * rule, dispatch apply.
     */
    public function lockdownCacheToLoopback(CacheServiceNetworkExposure $exposure, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            return;
        }

        try {
            $this->runConsoleAction(
                $row,
                'cache_lockdown',
                __('Lock :engine on :host to loopback', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($exposure, $row, $audits): void {
                    $emit->step('cache', 'Rewriting bind to 127.0.0.1; removing firewall rule');
                    $exposure->lockdown($row->server, $row, auth()->id());
                    $emit->success('cache', 'Locked down; firewall apply queued.');

                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
                        ['engine' => $row->engine, 'name' => $row->name, 'change' => 'locked_down'],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->toastSuccess(__(':engine locked down to loopback — firewall apply queued.', ['engine' => $row->engine]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }
}
