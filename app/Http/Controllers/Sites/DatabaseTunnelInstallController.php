<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSshSession;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Database\Services\TunnelAccessProvisioner;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use App\Support\Servers\DatabaseJumpHostAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Serves a one-shot shell script that installs database-tunnel access locally.
 *
 * The script writes the minted private key and — the part that actually removes
 * the friction — an SSH config block, so the tunnel command becomes
 * `ssh -f -N dply-db-xxxx` with no key path to know, no -i to remember, and
 * exactly one identity offered (which is what stops MaxAuthTries being tripped).
 *
 * Signed AND session-authorized, and the private key is cleared from the record
 * once delivered: the script is good for one run.
 */
final class DatabaseTunnelInstallController extends Controller
{
    public function __invoke(
        Request $request,
        Server $server,
        Site $site,
        string $binding,
        DatabaseConnectionTargetResolver $resolver,
        TunnelAccessProvisioner $provisioner,
    ): Response {
        abort_unless((string) $site->server_id === (string) $server->id, 404);

        Gate::authorize('update', $site);

        $user = Auth::user();
        abort_if($user === null, 403);

        $siteBinding = SiteBinding::query()->where('site_id', $site->id)->find($binding);
        abort_unless($siteBinding instanceof SiteBinding, 404);

        $target = $resolver->forBinding($siteBinding);
        abort_unless($target instanceof DatabaseConnectionTarget, 404);
        abort_unless($resolver->tunnelUnavailableReason($target, $server) === null, 404);

        $session = $provisioner->provision($server, $user, $target);
        $privateKey = (string) $session->private_key;
        abort_if($privateKey === '', 500, 'Could not mint tunnel access.');

        // The stored copy is cleared immediately: the key exists in exactly one
        // place after this response, the operator's machine. Re-running the
        // installer mints a fresh key and revokes this one.
        $session->forceFill(['private_key' => null, 'delivered_at' => now()])->save();

        audit_log(
            $site->organization,
            $user,
            'server.ssh_session.tunnel_key_delivered',
            $session,
            null,
            ['server_id' => (string) $server->id, 'database_host' => $target->host],
        );

        $localPort = (int) $request->query('port', (string) DatabaseJumpHostAccess::BASE_LOCAL_PORT);

        return response(
            $this->script($session, $server, $target, $privateKey, $localPort),
            200,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Referrer-Policy' => 'no-referrer',
            ],
        );
    }

    private function script(
        ServerSshSession $session,
        Server $server,
        DatabaseConnectionTarget $target,
        string $privateKey,
        int $localPort,
    ): string {
        $alias = TunnelAccessProvisioner::aliasFor($session);
        $user = DatabaseJumpHostAccess::sshUserFor($server);
        $sshPort = (int) ($server->ssh_port ?: 22);
        $expires = $session->expires_at?->toDayDateTimeString() ?? 'soon';

        $keyPath = '$HOME/.dply/keys/'.$alias;
        $connectUri = $target->uri(null, '127.0.0.1', $localPort);

        return <<<BASH
        #!/usr/bin/env bash
        # dply — database tunnel access for {$target->label}
        #
        # Installs a purpose-minted SSH key that can ONLY forward to
        # {$target->host}:{$target->port} on {$server->name}.
        # It cannot open a shell. It expires {$expires}.
        set -euo pipefail

        KEY_PATH="{$keyPath}"
        ALIAS="{$alias}"

        mkdir -p "\$(dirname "\$KEY_PATH")"
        umask 077

        cat > "\$KEY_PATH" <<'DPLY_KEY'
        {$privateKey}
        DPLY_KEY
        chmod 600 "\$KEY_PATH"

        mkdir -p "\$HOME/.ssh"
        touch "\$HOME/.ssh/config"
        chmod 600 "\$HOME/.ssh/config"

        # Replace any previous block for this alias so re-running is safe.
        if grep -q "^Host \$ALIAS\$" "\$HOME/.ssh/config" 2>/dev/null; then
          awk -v alias="Host \$ALIAS" '
            \$0 == alias {skip=1; next}
            skip && /^Host / {skip=0}
            !skip {print}
          ' "\$HOME/.ssh/config" > "\$HOME/.ssh/config.dply.tmp"
          mv "\$HOME/.ssh/config.dply.tmp" "\$HOME/.ssh/config"
          chmod 600 "\$HOME/.ssh/config"
        fi

        cat >> "\$HOME/.ssh/config" <<DPLY_CONFIG

        Host {$alias}
          HostName {$server->ip_address}
          User {$user}
          Port {$sshPort}
          IdentityFile \$KEY_PATH
          IdentitiesOnly yes
          StrictHostKeyChecking accept-new
        DPLY_CONFIG

        echo ""
        echo "Installed tunnel access as '{$alias}'."
        echo ""
        echo "Open the tunnel:"
        echo "  ssh -f -N -L {$localPort}:{$target->host}:{$target->port} {$alias}"
        echo ""
        echo "Then connect your client to:"
        echo "  {$connectUri}"
        echo ""
        echo "Access expires {$expires}."
        BASH;
    }
}
