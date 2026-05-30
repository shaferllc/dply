<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Servers\TraefikDashboardProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Authenticated GET proxy to Traefik's localhost dashboard (:9094) over SSH.
 */
class TraefikDashboardProxyController extends Controller
{
    public function __invoke(Request $request, Server $server, ?string $path = null): SymfonyResponse
    {
        $this->authorize('view', $server);

        if (! $request->isMethod('GET')) {
            abort(405, 'Only GET is allowed on the Traefik dashboard proxy.');
        }

        $org = $request->user()?->currentOrganization();
        if ($org !== null && $org->userIsDeployer($request->user())) {
            abort(403, 'Deployers cannot access the Traefik dashboard.');
        }

        if ($server->edgeProxy() !== 'traefik') {
            abort(404, 'This server does not have Traefik as its edge proxy.');
        }

        $proxyPrefix = rtrim(route('servers.traefik.dashboard', ['server' => $server]), '/');

        $fetchPath = $this->resolveFetchPath($request, $server, $path);

        try {
            $result = app(TraefikDashboardProxy::class)->fetch(
                $server,
                $fetchPath,
                $proxyPrefix,
            );
        } catch (\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            return response()->view('errors.traefik-dashboard', [
                'message' => $e->getMessage(),
                'server' => $server,
            ], 503);
        }

        $status = (int) ($result['status'] ?? 502);
        if ($status < 100 || $status > 599) {
            $status = 502;
        }

        return response(
            (string) ($result['body'] ?? ''),
            $status,
            [
                'Content-Type' => (string) ($result['content_type'] ?? 'text/html; charset=utf-8'),
                'X-Dply-Traefik-Dashboard-Target' => (string) ($result['target_url'] ?? ''),
                'Cache-Control' => 'no-store, private',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
        );
    }

    private function resolveFetchPath(Request $request, Server $server, ?string $path): string
    {
        if ($request->routeIs('servers.traefik.dashboard.assets')) {
            return 'assets/'.ltrim((string) $path, '/');
        }

        if ($request->routeIs('servers.traefik.api')) {
            $apiPath = trim((string) ($path ?? ''), '/');

            return $apiPath === '' ? 'api' : 'api/'.$apiPath;
        }

        $fromUri = $this->traefikSubPathFromRequest($request, $server);
        if ($fromUri !== '') {
            return $fromUri;
        }

        return trim((string) ($path ?? ''), '/');
    }

    private function traefikSubPathFromRequest(Request $request, Server $server): string
    {
        $pathInfo = $request->getPathInfo();
        $prefix = '/servers/'.$server->id.'/traefik/';

        if (! str_starts_with($pathInfo, $prefix)) {
            return '';
        }

        $remainder = substr($pathInfo, strlen($prefix));

        if (str_starts_with($remainder, 'dashboard')) {
            return ltrim(substr($remainder, strlen('dashboard')), '/');
        }

        if (str_starts_with($remainder, 'api')) {
            $apiPath = ltrim(substr($remainder, strlen('api')), '/');

            return $apiPath === '' ? 'api' : 'api/'.$apiPath;
        }

        return '';
    }
}
