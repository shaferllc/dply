<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\QuickDownloadController;
use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Database\Services\TrustedSourceManager;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Hands a fully-populated connection URI to the operator's desktop client.
 *
 * The password cannot live in the rendered page — it would sit in the DOM of a
 * workspace tab anyone could shoulder-read or a screenshot could leak. Reaching
 * it therefore needs BOTH a short-lived signed URL (minted into the Connect
 * modal for one operator) AND an authenticated session authorized on the site,
 * exactly like {@see QuickDownloadController}.
 *
 * The response is a minimal page that immediately hands off to the client's URL
 * scheme rather than a 302: browsers do not reliably follow a Location header
 * into a non-HTTP scheme, and a redirect would also park the secret in history.
 */
final class DatabaseConnectLinkController extends Controller
{
    public function __invoke(
        Request $request,
        Server $server,
        Site $site,
        string $binding,
        DatabaseConnectionTargetResolver $resolver,
    ): Response {
        abort_unless((string) $site->server_id === (string) $server->id, 404);

        Gate::authorize('update', $site);

        $siteBinding = SiteBinding::query()
            ->where('site_id', $site->id)
            ->find($binding);

        abort_unless($siteBinding instanceof SiteBinding, 404);

        $target = $resolver->forBinding($siteBinding);
        abort_unless($target instanceof DatabaseConnectionTarget, 404);

        // Granting here rather than in a Livewire action keeps the button a real
        // anchor, so it can open in a new tab on a genuine user gesture. A
        // Livewire redirect navigated the workspace away, and window.open from
        // an XHR response is unreliable under popup blockers.
        if ($request->query('allow') === '1') {
            $this->grantAccess($request, $siteBinding);
        }

        $password = $this->password($siteBinding);
        abort_if($password === null, 404);

        // "tunnel" means the operator is forwarding to 127.0.0.1 themselves —
        // TablePlus's URL scheme cannot express an SSH leg, so the link points
        // at the forwarded port and only works while their tunnel is up.
        $viaTunnel = $request->query('via') === 'tunnel';
        $localPort = (int) $request->query('port', (string) $target->port);

        $uri = $viaTunnel
            ? $target->uri($password, '127.0.0.1', $localPort)
            : $target->uri($password);

        $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query(array_filter([
            'name' => $target->label !== '' ? $target->label.' · '.$site->name : $site->name,
            'env' => $this->environmentTag($site),
        ]));

        audit_log(
            $site->organization,
            Auth::user(),
            'cloud.databases.connection_link_opened',
            $siteBinding,
            null,
            ['via' => $viaTunnel ? 'tunnel' : 'direct', 'host' => $target->host],
        );

        return response()
            ->view('sites.database-connect-handoff', [
                'uri' => $uri,
                'label' => $target->label !== '' ? $target->label : $site->name,
            ])
            // Belt and braces: this response body contains a credential, so no
            // shared cache or history entry should ever retain it.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Add the operator's address to the cluster allowlist before handing off.
     *
     * Admin-gated exactly as the Livewire action is — changing a production
     * database's network exposure is not something a signed link alone should
     * be able to do.
     */
    private function grantAccess(Request $request, SiteBinding $binding): void
    {
        $user = Auth::user();
        $site = $binding->site;

        if ($user === null || $site === null || ! $site->organization?->hasAdminAccess($user)) {
            return;
        }

        if ($binding->target_type !== 'cloud_database' || blank($binding->target_id)) {
            return;
        }

        $cluster = CloudDatabase::query()->find($binding->target_id);
        if (! $cluster instanceof CloudDatabase) {
            return;
        }

        $ip = trim((string) $request->query('ip', ''));
        if ($ip === '') {
            $ip = trim((string) $request->ip());
        }

        $manager = app(TrustedSourceManager::class);
        if (! $manager->supports($cluster)) {
            return;
        }

        try {
            $manager->allow($cluster, $ip, $user);
        } catch (\Throwable) {
            // The hand-off still proceeds: the operator may already hold a live
            // allowance, and a failure here is surfaced by the connection itself.
        }
    }

    /**
     * The password, read server-side only. Managed clusters keep it in the
     * encrypted connection blob; operator-typed hosts only in injected_env.
     */
    private function password(SiteBinding $binding): ?string
    {
        if ($binding->target_type === 'cloud_database' && filled($binding->target_id)) {
            $cluster = CloudDatabase::query()->find($binding->target_id);
            if ($cluster instanceof CloudDatabase) {
                $connection = $cluster->getAttribute('connection');
                $password = is_array($connection) ? (string) ($connection['password'] ?? '') : '';

                return $password !== '' ? $password : null;
            }
        }

        $password = (string) ($binding->connectionEnv()['DB_PASSWORD'] ?? '');

        return $password !== '' ? $password : null;
    }

    private function environmentTag(Site $site): string
    {
        $env = strtolower(trim((string) ($site->deployment_environment ?? '')));

        return match ($env) {
            'staging' => 'staging',
            'development', 'dev', 'local' => 'local',
            'test', 'preview' => 'testing',
            default => 'production',
        };
    }
}
