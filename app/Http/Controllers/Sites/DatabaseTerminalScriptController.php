<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\SiteBinding;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Downloads a `.command` file that opens a terminal session on the database.
 *
 * A browser cannot start a process, so this is the closest thing to one-click
 * terminal access on macOS: double-clicking a `.command` file runs it in
 * Terminal. The script brings the tunnel up if it is not already listening,
 * resolves the credential from a short-lived signed URL (never baked into the
 * file), and execs the engine's own client so the operator lands at a prompt.
 *
 * Signature-only, like the other shell-facing endpoints — it is fetched by a
 * browser download and then run offline, so no session travels with it.
 */
final class DatabaseTerminalScriptController extends Controller
{
    public function __invoke(
        Request $request,
        string $binding,
        DatabaseConnectionTargetResolver $resolver,
    ): Response {
        $siteBinding = SiteBinding::query()->find($binding);
        abort_unless($siteBinding instanceof SiteBinding, 404);

        $target = $resolver->forBinding($siteBinding);
        abort_unless($target instanceof DatabaseConnectionTarget, 404);

        $connectAs = trim((string) $request->query('as', ''));
        if ($connectAs !== '') {
            $target = $target->as($connectAs);
        }

        $localPort = (int) $request->query('port', (string) $target->port);
        $alias = trim((string) $request->query('alias', ''));
        $uriUrl = trim((string) $request->query('uri_url', ''));
        $viaTunnel = $alias !== '';

        $filename = Str::slug($target->label ?: 'database').'-terminal.command';

        return response(
            $this->script($target, $localPort, $alias, $uriUrl, $viaTunnel),
            200,
            [
                'Content-Type' => 'application/x-sh; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Referrer-Policy' => 'no-referrer',
            ],
        );
    }

    private function script(
        DatabaseConnectionTarget $target,
        int $localPort,
        string $alias,
        string $uriUrl,
        bool $viaTunnel,
    ): string {
        $host = $viaTunnel ? '127.0.0.1' : $target->host;
        $port = $viaTunnel ? $localPort : $target->port;

        $client = match (true) {
            $target->isMysqlFamily() => 'mysql',
            $target->isRedis() => 'redis-cli',
            default => 'psql',
        };

        $tunnel = $viaTunnel ? <<<TUNNEL

        if ! nc -z 127.0.0.1 {$localPort} 2>/dev/null; then
          echo "Opening the tunnel…"
          ssh -f -N -L {$localPort}:{$target->host}:{$target->port} {$alias} || {
            echo "Could not open the tunnel. Has access been set up for this database?"
            exit 1
          }
          sleep 1
        fi
        TUNNEL : '';

        // psql and mysql take a URI directly; redis-cli does not.
        $invoke = $target->isRedis()
            ? sprintf('exec redis-cli -u "$DPLY_URI"')
            : sprintf('exec %s "$DPLY_URI"', $client);

        return <<<BASH
        #!/usr/bin/env bash
        # dply — terminal session for {$target->label}
        #
        # Opens the tunnel if needed, then drops you at a {$client} prompt.
        # The credential is fetched at run time and never stored in this file.
        set -uo pipefail
        {$tunnel}
        if ! command -v {$client} >/dev/null 2>&1; then
          echo "{$client} is not installed."
          echo "Install it, then connect to {$host}:{$port} yourself."
          echo ""
          read -r -p "Press return to close…" _
          exit 1
        fi

        echo "Fetching credentials…"
        DPLY_URI="\$(curl -fsSL "{$uriUrl}" || true)"

        if [ -z "\$DPLY_URI" ]; then
          echo "That connection link has expired. Reopen Connect in dply for a fresh one."
          echo ""
          read -r -p "Press return to close…" _
          exit 1
        fi

        echo "Connecting to {$target->label} as {$target->username}…"
        echo ""
        {$invoke}
        BASH;
    }
}
