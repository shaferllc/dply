<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseJumpHostAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * {@see DatabaseConnectLinkController} for a site's own on-box database — the
 * one a WordPress / Laravel scaffold creates, which has a ServerDatabase row but
 * no SiteBinding. Same guarantees: a signed URL AND a session authorized on the
 * site, the password read server-side only, handed off via a no-store page.
 *
 * Points at the forwarded 127.0.0.1 port, so it connects only while the
 * operator's tunnel is up — TablePlus's URL scheme cannot express the SSH leg.
 */
final class ServerDatabaseConnectLinkController extends Controller
{
    public function __invoke(Request $request, Server $server, Site $site, string $database): Response
    {
        abort_unless((string) $site->server_id === (string) $server->id, 404);

        Gate::authorize('update', $site);

        $db = ServerDatabase::query()->where('server_id', $server->id)->find($database);
        abort_unless($db instanceof ServerDatabase && $db->hasUsableCredentials() && filled($db->password), 404);

        $port = (int) $request->query('port', (string) DatabaseJumpHostAccess::BASE_LOCAL_PORT);
        $target = DatabaseConnectionTarget::fromServerDatabase($db, '127.0.0.1', $db->defaultPort());

        $uri = $target->uri((string) $db->password, '127.0.0.1', $port);
        $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query(['name' => $site->name]);

        audit_log(
            $site->organization,
            Auth::user(),
            'databases.connection_link_opened',
            $db,
            null,
            ['via' => 'tunnel', 'site_id' => $site->id],
        );

        return response()
            ->view('sites.database-connect-handoff', [
                'uri' => $uri,
                'label' => $site->name,
            ])
            // The body carries a credential: no shared cache or history entry.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
