<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSshSession;
use App\Modules\Database\Services\TunnelAccessProvisioner;
use App\Support\Servers\DatabaseJumpHostAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves a one-shot shell script that installs database-tunnel access locally.
 *
 * The script writes the minted private key and — the part that actually removes
 * the friction — an SSH config block, so the tunnel command becomes
 * `ssh -f -N -L … dply-db-xxxx` with no key path to know and exactly one
 * identity offered (which is what stops MaxAuthTries being tripped).
 *
 * NOTE ON AUTHORIZATION. This route is signature-only, with no session check,
 * because it is fetched by `curl | bash` — curl carries no session cookie, so a
 * Gate check here simply redirects it to the login page and the shell receives
 * HTML instead of a script. The signed URL is the capability, and it is a narrow
 * one:
 *
 *  - the key was authorized when it was MINTED, in the Connect modal, by an
 *    operator with update rights on the site;
 *  - the URL expires within minutes;
 *  - the private key is cleared on first fetch, so a replayed URL yields nothing;
 *  - the key is permitopen-restricted to one database and can never open a shell;
 *  - it is time-boxed and reaped like any other SSH session.
 */
final class DatabaseTunnelInstallController extends Controller
{
    public function __invoke(Request $request, string $session): Response
    {
        $sshSession = ServerSshSession::query()->with('server')->find($session);
        abort_unless($sshSession instanceof ServerSshSession, 404);

        $privateKey = (string) $sshSession->private_key;
        abort_if(
            $privateKey === '',
            410,
            "This install link has already been used. Open the database's Connect panel to set up access again.\n",
        );

        abort_if(
            $sshSession->expires_at->isPast() || $sshSession->revoked_at !== null,
            410,
            "This access has expired.\n",
        );

        $server = $sshSession->server;
        abort_unless($server instanceof Server, 404);

        // Cleared immediately: after this response the key exists in exactly one
        // place, the operator's machine.
        $sshSession->forceFill(['private_key' => null, 'delivered_at' => now()])->save();

        $dbHost = trim((string) $request->query('host', ''));
        $dbPort = (int) $request->query('dbport', '0');
        $localPort = (int) $request->query('port', (string) DatabaseJumpHostAccess::BASE_LOCAL_PORT);

        return response(
            $this->script($sshSession, $server, $privateKey, $dbHost, $dbPort, $localPort),
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
        string $privateKey,
        string $dbHost,
        int $dbPort,
        int $localPort,
    ): string {
        $alias = TunnelAccessProvisioner::aliasFor($session);
        $user = DatabaseJumpHostAccess::sshUserFor($server);
        $sshPort = (int) ($server->ssh_port ?: 22);
        $expires = $session->expires_at->toDayDateTimeString();
        $keyPath = '$HOME/.dply/keys/'.$alias;

        // Signed URL that yields the full URI, password included. Clients do not
        // prompt for a missing password — they fail with "no password supplied" —
        // so the script resolves it at run time rather than shipping a URI that
        // cannot authenticate.
        $uriUrl = trim((string) request()->query('uri_url', ''));

        $canConnectNow = $dbHost !== '' && $dbPort > 0;
        $tunnelHint = $canConnectNow
            ? sprintf('ssh -f -N -L %d:%s:%d %s', $localPort, $dbHost, $dbPort, $alias)
            : sprintf('ssh -f -N -L %d:<database-host>:<port> %s', $localPort, $alias);

        // Finish the job: setting up access is only useful if it also connects,
        // and the operator already has a terminal open running this script.
        $connectNow = $canConnectNow ? <<<CONNECT

        if command -v nc >/dev/null 2>&1 && ! nc -z 127.0.0.1 {$localPort} 2>/dev/null; then
          echo "Opening the tunnel…"
          ssh -f -N -L {$localPort}:{$dbHost}:{$dbPort} {$alias} || true
          sleep 1
        fi

        if [ -d "/Applications/TablePlus.app" ] && [ -n "{$uriUrl}" ]; then
          echo "Launching TablePlus…"
          DPLY_URI="\$(curl -fsSL "{$uriUrl}" || true)"
          [ -n "\$DPLY_URI" ] && open -a TablePlus "\$DPLY_URI" || true
        fi
        CONNECT : '';

        return <<<BASH
        #!/usr/bin/env bash
        # dply — database tunnel access
        #
        # Installs a purpose-minted SSH key that can only forward to one database.
        # It cannot open a shell. Access expires {$expires}.
        set -euo pipefail

        KEY_PATH="{$keyPath}"

        mkdir -p "\$(dirname "\$KEY_PATH")"
        umask 077

        cat > "\$KEY_PATH" <<'DPLY_KEY'
        {$privateKey}
        DPLY_KEY
        chmod 600 "\$KEY_PATH"

        mkdir -p "\$HOME/.ssh"
        touch "\$HOME/.ssh/config"
        chmod 600 "\$HOME/.ssh/config"

        # Drop any previous block for this alias so re-running stays idempotent.
        if grep -q "^Host {$alias}\$" "\$HOME/.ssh/config" 2>/dev/null; then
          awk '
            \$0 == "Host {$alias}" {skip=1; next}
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
        echo "Open the tunnel with:"
        echo "  {$tunnelHint}"
        echo ""
        echo "Access expires {$expires}."
        {$connectNow}
        BASH;
    }
}
