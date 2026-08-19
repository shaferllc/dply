<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Database\Services\ManagedDatabaseUsers;
use App\Modules\Database\Services\TrustedSourceManager;
use App\Modules\Database\Services\TunnelAccessProvisioner;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use App\Support\Servers\DatabaseJumpHostAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * "Connect with TablePlus / psql / any client" for a hosted database.
 *
 * Renders read-only connection facts plus a ready `ssh -L` tunnel command. The
 * password is deliberately absent — it travels only through the one-time
 * credential-share channel, so nothing here can leak it into the DOM.
 *
 * Mounted from both the site Databases tab and the Environment resource map.
 *
 * @property-read ?DatabaseConnectionTarget $target
 */
class DatabaseConnect extends Component
{
    use AuthorizesRequests;

    public Site $site;

    public Server $server;

    public string $bindingId = '';

    /** Local port the emitted tunnel binds; editable because 15432 may be taken. */
    public int $localPort = DatabaseJumpHostAccess::BASE_LOCAL_PORT;

    public bool $open = false;

    /**
     * Address to allow. Prefilled from the request when it is a routable public
     * IPv4, but editable: request()->ip() is 127.0.0.1 in local dev and is the
     * proxy's address behind any load balancer dply does not trust, and in both
     * cases the operator still knows their own address.
     */
    public string $allowIp = '';

    /**
     * Local private key to pin with -i. Editable because dply knows which public
     * key is authorized on the box but has no idea where the operator keeps the
     * matching private half.
     */
    public string $sshKeyPath = '~/.ssh/id_ed25519';

    /** Database user to connect as. Empty means the cluster admin. */
    public string $connectAs = '';

    /** Name for a user being created, when the operator wants a non-admin login. */
    public string $newUserName = '';

    public bool $creatingUser = false;

    /** Minted tunnel session, once the operator has asked to set access up. */
    public string $tunnelSessionId = '';

    public function mount(Site $site, Server $server, string $bindingId): void
    {
        $this->site = $site;
        $this->server = $server;
        $this->bindingId = $bindingId;
    }

    public function openConnect(): void
    {
        $this->authorize('update', $this->site);

        if ($this->allowIp === '') {
            $this->allowIp = (string) ($this->operatorIp() ?? '');
        }

        $this->open = true;
        $this->dispatch('open-modal', 'database-connect-'.$this->bindingId);
    }

    public function closeConnect(): void
    {
        $this->dispatch('close-modal', 'database-connect-'.$this->bindingId);
    }

    public function updatedLocalPort(): void
    {
        // Keep the emitted command inside the ephemeral range operators can
        // actually bind without privileges.
        $this->localPort = max(1024, min(65535, $this->localPort));
    }

    /**
     * Grant this operator's address temporary access to the cluster.
     *
     * Admin-gated: it changes a production database's network exposure. The
     * manager preserves every rule it did not create, including the app
     * server's — see TrustedSourceManager.
     */
    public function allowMyIp(TrustedSourceManager $manager): void
    {
        $this->authorize('update', $this->site);

        $user = auth()->user();
        $cluster = $this->cluster($this->binding());

        if ($user === null || ! $cluster instanceof CloudDatabase) {
            return;
        }

        if (! $this->site->organization?->hasAdminAccess($user)) {
            $this->dispatch('toast', type: 'error', message: __('Only organization admins can change database network access.'));

            return;
        }

        $ip = trim($this->allowIp) !== '' ? trim($this->allowIp) : (string) ($this->operatorIp() ?? '');
        if (! self::isPublicIp($ip)) {
            $this->dispatch('toast', type: 'error', message: __('Enter a routable public IP address to allow.'));

            return;
        }

        try {
            $record = $manager->allow($cluster, $ip, $user);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Access granted until :time.', [
            'time' => $record->expires_at->diffForHumans(),
        ]));
    }

    public function revokeMyIp(TrustedSourceManager $manager): void
    {
        $this->authorize('update', $this->site);

        $user = auth()->user();
        $cluster = $this->cluster($this->binding());

        if ($user === null || ! $cluster instanceof CloudDatabase) {
            return;
        }

        $record = $manager->liveForUser($cluster, $user);
        if ($record === null) {
            return;
        }

        try {
            $manager->revoke($record, $user);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Access revoked.'));
    }

    /**
     * Mint forward-only tunnel access for this operator.
     *
     * Authorization happens HERE rather than on the install URL: that URL is
     * fetched by curl, which carries no session, so the check has to sit on the
     * action that creates the key.
     */
    public function setUpTunnelAccess(TunnelAccessProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);

        $user = auth()->user();
        $binding = $this->binding();
        $target = $binding !== null
            ? app(DatabaseConnectionTargetResolver::class)->forBinding($binding)
            : null;

        if ($user === null || ! $target instanceof DatabaseConnectionTarget) {
            return;
        }

        try {
            $session = $provisioner->provision($this->server, $user, $target);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->tunnelSessionId = (string) $session->id;
    }

    public function startCreatingUser(): void
    {
        $this->authorize('update', $this->site);

        $this->creatingUser = true;
        if ($this->newUserName === '') {
            $this->newUserName = ManagedDatabaseUsers::suggestedName(
                (string) (auth()->user()->name ?: 'dply user'),
            );
        }
    }

    public function cancelCreatingUser(): void
    {
        $this->creatingUser = false;
    }

    public function createUser(ManagedDatabaseUsers $users): void
    {
        $this->authorize('update', $this->site);

        $cluster = $this->cluster($this->binding());
        if (! $cluster instanceof CloudDatabase) {
            return;
        }

        try {
            $created = $users->create($cluster, $this->newUserName);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        // Connect as the new user straight away — that is the whole point of
        // creating it.
        $this->connectAs = $created['name'];
        $this->creatingUser = false;
        $this->newUserName = '';

        $this->dispatch('toast', type: 'success', message: __('Created :user.', ['user' => $created['name']]));
    }

    public function binding(): ?SiteBinding
    {
        if ($this->bindingId === '') {
            return null;
        }

        return SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->find($this->bindingId);
    }

    public function render(): View
    {
        $binding = $this->binding();
        $resolver = app(DatabaseConnectionTargetResolver::class);

        $target = $binding instanceof SiteBinding ? $resolver->forBinding($binding) : null;

        // Everything downstream — commands, URIs, hand-off links — is built for
        // whichever user the operator chose, so the choice is applied once here.
        if ($target instanceof DatabaseConnectionTarget && $this->connectAs !== '') {
            $target = $target->as($this->connectAs);
        }

        $reason = null;
        $tunnel = null;
        $allowance = null;
        $canAllowIp = false;
        $databaseUsers = [];
        $canManageUsers = false;

        if ($target instanceof DatabaseConnectionTarget) {
            $reason = $resolver->tunnelUnavailableReason($target, $this->server);

            if ($reason === null) {
                $tunnel = DatabaseJumpHostAccess::tunnelCommandsFor(
                    $target,
                    $this->server,
                    $this->localPort,
                    $this->sshKeyPath,
                );
            }

            $cluster = $this->cluster($binding);
            if ($cluster instanceof CloudDatabase) {
                $manager = app(TrustedSourceManager::class);
                // Changing a production cluster's network exposure is an admin
                // action; viewing the connection shape is not.
                $user = auth()->user();
                $canAllowIp = $manager->supports($cluster)
                    && Gate::allows('update', $this->site)
                    && $user !== null
                    && (bool) $this->site->organization?->hasAdminAccess($user);
                $allowance = $user !== null ? $manager->liveForUser($cluster, $user) : null;

                $userManager = app(ManagedDatabaseUsers::class);
                $canManageUsers = $userManager->supports($cluster);
                $databaseUsers = $canManageUsers ? $userManager->list($cluster) : [];
            }
        }

        return view('livewire.sites.database-connect', [
            // Direct is offered whenever it can actually work: the provider is
            // public, or this operator already holds an allowlist entry. It used
            // to be suppressed whenever a tunnel was possible, which hid the only
            // genuinely one-click path behind a command the operator had to run.
            'directLink' => $target instanceof DatabaseConnectionTarget
                && ($target->publiclyReachable || $allowance !== null)
                    ? $this->connectLink($target, false)
                    : null,
            'tunnelLink' => $target instanceof DatabaseConnectionTarget && $reason === null
                ? $this->connectLink($target, true)
                : null,
            'tunnelInstallCommand' => $reason === null ? $this->tunnelInstallCommand($target) : null,
            'tunnelAlias' => $reason === null ? $this->existingTunnelAlias() : null,
            'allowAndOpenLink' => $canAllowIp && $allowance === null
                ? $this->allowAndOpenLink($target)
                : null,
            'launchCommand' => $reason === null ? $this->launchCommand($target) : null,
            'terminalScriptLink' => $this->terminalScriptLink($target),
            'target' => $target,
            'databaseUsers' => $databaseUsers,
            'canManageUsers' => $canManageUsers,
            'tunnel' => $tunnel,
            'unavailableReason' => $reason,
            'canAllowIp' => $canAllowIp,
            'allowance' => $allowance,
            'operatorIp' => $this->operatorIp(),
            'isProduction' => $this->looksProduction(),
        ]);
    }

    /**
     * Short-lived signed hand-off URL. Two minutes is plenty for a click and
     * keeps a copied link from being useful later; it is still session-gated at
     * the controller, so the signature alone opens nothing.
     */
    private function connectLink(?DatabaseConnectionTarget $target, bool $viaTunnel): ?string
    {
        if (! $target instanceof DatabaseConnectionTarget) {
            return null;
        }

        return URL::temporarySignedRoute('sites.databases.connect-link', now()->addMinutes(2), [
            'server' => $this->server->id,
            'site' => $this->site->id,
            'binding' => $this->bindingId,
            'via' => $viaTunnel ? 'tunnel' : 'direct',
            'port' => $viaTunnel ? $this->localPort : $target->port,
            'as' => $this->connectAs !== '' ? $this->connectAs : null,
        ]);
    }

    /**
     * Downloadable .command that opens a terminal session on the database.
     *
     * A browser cannot start a process; a double-clicked .command file can. This
     * is the nearest thing to one-click terminal access on macOS.
     */
    private function terminalScriptLink(?DatabaseConnectionTarget $target): ?string
    {
        if (! $target instanceof DatabaseConnectionTarget) {
            return null;
        }

        $alias = $this->existingTunnelAlias();

        return URL::temporarySignedRoute('database-connections.terminal', now()->addMinutes(15), [
            'binding' => $this->bindingId,
            'port' => $this->localPort,
            'alias' => $alias,
            'as' => $this->connectAs !== '' ? $this->connectAs : null,
            'uri_url' => URL::temporarySignedRoute('database-connections.uri', now()->addHours(12), [
                'binding' => $this->bindingId,
                'via' => $alias !== null ? 'tunnel' : 'direct',
                'port' => $this->localPort,
                'as' => $this->connectAs !== '' ? $this->connectAs : null,
            ]),
        ]);
    }

    /**
     * Signed link that grants access and hands off in a single navigation.
     *
     * Rendered as a plain anchor with target="_blank" so the workspace tab is
     * never navigated away and no popup blocker is involved.
     */
    private function allowAndOpenLink(?DatabaseConnectionTarget $target): ?string
    {
        if (! $target instanceof DatabaseConnectionTarget) {
            return null;
        }

        $ip = trim($this->allowIp) !== '' ? trim($this->allowIp) : (string) ($this->operatorIp() ?? '');
        if (! self::isPublicIp($ip)) {
            return null;
        }

        return URL::temporarySignedRoute('sites.databases.connect-link', now()->addMinutes(2), [
            'server' => $this->server->id,
            'site' => $this->site->id,
            'binding' => $this->bindingId,
            'via' => 'direct',
            'port' => $target->port,
            'allow' => '1',
            'ip' => $ip,
            'as' => $this->connectAs !== '' ? $this->connectAs : null,
        ]);
    }

    /**
     * curl|bash one-liner that installs a minted, forward-only key plus an SSH
     * config block. Signed and session-gated, and the key is delivered once.
     */
    private function tunnelInstallCommand(?DatabaseConnectionTarget $target): ?string
    {
        if ($this->tunnelSessionId === '' || ! $target instanceof DatabaseConnectionTarget) {
            return null;
        }

        $url = URL::temporarySignedRoute('database-tunnels.install', now()->addMinutes(10), [
            'session' => $this->tunnelSessionId,
            'host' => $target->host,
            'dbport' => $target->port,
            'port' => $this->localPort,
            'uri_url' => URL::temporarySignedRoute('database-connections.uri', now()->addMinutes(15), [
                'binding' => $this->bindingId,
                'via' => 'tunnel',
                'port' => $this->localPort,
                'as' => $this->connectAs !== '' ? $this->connectAs : null,
            ]),
        ]);

        return 'curl -fsSL '.escapeshellarg($url).' | bash';
    }

    /**
     * Alias of an already-installed tunnel key, so the UI can show the short
     * command instead of asking the operator to install again.
     */
    private function existingTunnelAlias(): ?string
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $session = app(TunnelAccessProvisioner::class)->activeFor($this->server, $user);

        return $session !== null ? TunnelAccessProvisioner::aliasFor($session) : null;
    }

    /**
     * One command that gets the operator connected, whatever state they are in.
     *
     * Reuses a tunnel that is already up rather than failing with "address
     * already in use" (ssh -f -N backgrounds itself, so a second run collides
     * with the first), opens one only when needed, then hands off to the client.
     * nc rather than bash's /dev/tcp because the default shell here is zsh,
     * which has no /dev/tcp.
     */
    private function launchCommand(?DatabaseConnectionTarget $target): ?string
    {
        if (! $target instanceof DatabaseConnectionTarget || $target->host === '') {
            return null;
        }

        $alias = $this->existingTunnelAlias();
        $sshTarget = $alias !== null
            ? $alias
            : DatabaseJumpHostAccess::sshUserFor($this->server).'@'.$this->server->ip_address;

        $sshPort = (int) ($this->server->ssh_port ?: 22);
        $portFlag = $sshPort === 22 ? '' : ' -p '.$sshPort;
        $identity = $alias !== null ? '' : ' -o IdentitiesOnly=yes -i '.$this->sshKeyPath;

        // The URI is fetched at run time rather than baked in: clients do not
        // prompt for a missing password, but embedding one would leave a live
        // credential in the clipboard and shell history. The signed URL expires.
        $uriUrl = URL::temporarySignedRoute('database-connections.uri', now()->addMinutes(10), [
            'binding' => $this->bindingId,
            'via' => 'tunnel',
            'port' => $this->localPort,
            'as' => $this->connectAs !== '' ? $this->connectAs : null,
        ]);

        return sprintf(
            'nc -z 127.0.0.1 %d 2>/dev/null || ssh -f -N%s%s -L %d:%s:%d %s; open -a TablePlus "$(curl -fsSL %s)"',
            $this->localPort,
            $identity,
            $portFlag,
            $this->localPort,
            $target->host,
            $target->port,
            $sshTarget,
            escapeshellarg($uriUrl),
        );
    }

    private function cluster(?SiteBinding $binding): ?CloudDatabase
    {
        if (! $binding instanceof SiteBinding || $binding->target_type !== 'cloud_database') {
            return null;
        }

        return CloudDatabase::query()->find($binding->target_id);
    }

    /**
     * The operator's public address, mirroring the validation the server-create
     * flow already uses for its /32 SSH allow rule.
     */
    private function operatorIp(): ?string
    {
        $ip = trim((string) request()->ip());

        return self::isPublicIp($ip) ? $ip : null;
    }

    /**
     * A private or reserved address on a managed cluster's allowlist is at best
     * useless and at worst misleading, so both detection and manual entry are
     * held to the same bar.
     */
    public static function isPublicIp(string $ip): bool
    {
        return $ip !== ''
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Best-effort production signal for the blast-radius warning. Deliberately
     * conservative: warn unless the site is clearly a non-production one.
     */
    private function looksProduction(): bool
    {
        $env = strtolower(trim((string) ($this->site->deployment_environment ?? '')));

        return ! in_array($env, ['staging', 'development', 'dev', 'test', 'preview', 'local'], true);
    }
}
