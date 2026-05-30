<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Servers\CaddyAdminApiProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Authenticated, read-only GET proxy to Caddy's localhost admin API.
 * Mutating admin calls (POST/PATCH/DELETE) are intentionally not exposed.
 */
class CaddyAdminApiProxyController extends Controller
{
    public function __invoke(Request $request, Server $server, ?string $path = null): SymfonyResponse
    {
        $this->authorize('view', $server);

        if (! $request->isMethod('GET')) {
            abort(405, 'Only GET is allowed on the Caddy admin proxy.');
        }

        $org = $request->user()?->currentOrganization();
        if ($org !== null && $org->userIsDeployer($request->user())) {
            abort(403, 'Deployers cannot access the Caddy admin API.');
        }

        if (strtolower((string) data_get($server->meta, 'webserver', 'nginx')) !== 'caddy') {
            abort(404, 'This server is not using Caddy.');
        }

        try {
            $result = app(CaddyAdminApiProxy::class)->fetch($server, (string) ($path ?? 'config'));
        } catch (\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        $status = (int) ($result['status'] ?? 502);
        if ($status < 100 || $status > 599) {
            $status = 502;
        }

        return response(
            (string) ($result['body'] ?? ''),
            $status,
            [
                'Content-Type' => (string) ($result['content_type'] ?? 'application/json'),
                'X-Dply-Caddy-Admin-Target' => (string) ($result['admin_url'] ?? ''),
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
