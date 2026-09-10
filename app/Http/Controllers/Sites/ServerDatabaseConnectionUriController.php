<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\ServerDatabase;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseJumpHostAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * {@see DatabaseConnectionUriController} for a site's own on-box database (a
 * ServerDatabase with no binding): the plain-text tunnel URI, password included,
 * that the tunnel installer and the one-paste launch fetch with curl.
 *
 * Signature-only for the same reason — curl carries no session — and minted
 * short-lived into the page for an operator authorized at that moment.
 */
final class ServerDatabaseConnectionUriController extends Controller
{
    public function __invoke(Request $request, string $database): Response
    {
        $db = ServerDatabase::query()->find($database);
        abort_unless($db instanceof ServerDatabase && $db->hasUsableCredentials() && filled($db->password), 404);

        $port = (int) $request->query('port', (string) DatabaseJumpHostAccess::BASE_LOCAL_PORT);
        $uri = DatabaseConnectionTarget::fromServerDatabase($db, '127.0.0.1', $db->defaultPort())
            ->uri((string) $db->password, '127.0.0.1', $port);

        return response($uri, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
