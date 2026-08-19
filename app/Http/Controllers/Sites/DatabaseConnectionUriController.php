<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\CloudDatabase;
use App\Models\SiteBinding;
use App\Modules\Database\Services\ManagedDatabaseUsers;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseConnectionTargetResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Returns a complete connection URI as plain text, for shell use.
 *
 * Exists because desktop clients do not prompt for a missing password — they
 * simply fail with "no password supplied" — so a copyable connect command has to
 * carry one. Embedding it directly in the command would park a live credential
 * in the operator's clipboard and shell history indefinitely; fetching it through
 * a short-lived signed URL means only the URL is left behind, and it stops
 * working within minutes.
 *
 * Signature-only for the same reason as the tunnel installer: it is fetched by
 * curl, which carries no session. The signed URL is minted into the Connect
 * modal for an operator who was authorized at that moment, and expires quickly.
 */
final class DatabaseConnectionUriController extends Controller
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

        $password = $connectAs !== ''
            ? $this->userPassword($siteBinding, $connectAs)
            : $this->password($siteBinding);
        abort_if($password === null, 404);

        $viaTunnel = $request->query('via') === 'tunnel';
        $localPort = (int) $request->query('port', (string) $target->port);

        $uri = $viaTunnel
            ? $target->uri($password, '127.0.0.1', $localPort)
            : $target->uri($password);

        return response($uri, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function userPassword(SiteBinding $binding, string $username): ?string
    {
        if ($binding->target_type !== 'cloud_database' || blank($binding->target_id)) {
            return null;
        }

        $cluster = CloudDatabase::query()->find($binding->target_id);

        return $cluster instanceof CloudDatabase
            ? app(ManagedDatabaseUsers::class)->passwordFor($cluster, $username)
            : null;
    }

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
}
