<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Servers\EnvoyAdminProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Authenticated GET proxy to Envoy's localhost admin UI (:9901) over SSH.
 */
class EnvoyAdminProxyController extends Controller
{
    public function __invoke(Request $request, Server $server, ?string $path = null): SymfonyResponse
    {
        $this->authorize('view', $server);

        if (! $request->isMethod('GET')) {
            abort(405, 'Only GET is allowed on the Envoy admin proxy.');
        }

        $org = $request->user()?->currentOrganization();
        if ($org !== null && $org->userIsDeployer($request->user())) {
            abort(403, 'Deployers cannot access the Envoy admin UI.');
        }

        if ($server->edgeProxy() !== 'envoy') {
            abort(404, 'This server does not have Envoy as its edge proxy.');
        }

        $proxyPrefix = rtrim(route('servers.envoy.admin', ['server' => $server]), '/');
        $fetchPath = trim((string) ($path ?? ''), '/');

        try {
            $result = app(EnvoyAdminProxy::class)->fetch(
                $server,
                $fetchPath,
                $proxyPrefix,
            );
        } catch (\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            return response()->view('errors.envoy-admin', [
                'message' => $e->getMessage(),
                'server' => $server,
            ], 503);
        }

        $status = (int) $result['status'];
        if ($status < 100 || $status > 599) {
            $status = 502;
        }

        return response(
            (string) $result['body'],
            $status,
            [
                'Content-Type' => (string) $result['content_type'],
                'X-Dply-Envoy-Admin-Target' => (string) $result['target_url'],
                'Cache-Control' => 'no-store, private',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
        );
    }
}
