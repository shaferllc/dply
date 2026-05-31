<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ServerCacheService;
use App\Models\ServerCredentialShare;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Reveals a server's credentials once, behind a short-lived token.
 *
 * Mirrors {@see DatabaseCredentialShareController}: the link is unauthenticated
 * but throttled, expires, and is consumed after a limited number of views. The
 * secret (cache/redis AUTH password) is decrypted from the server meta only at
 * the moment of reveal — it is never stored in the share row or sent by email.
 */
class ServerCredentialShareController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $share = ServerCredentialShare::query()
            ->where('token', $token)
            ->with('server')
            ->firstOrFail();

        if ($share->isExpired()) {
            abort(410, __('This link has expired.'));
        }

        if ($share->isExhausted()) {
            abort(410, __('This link has already been used.'));
        }

        $server = $share->server;
        abort_if($server === null, 404);

        // Consume one view before rendering so a crash mid-render can't grant
        // unlimited reveals.
        $share->decrement('views_remaining');

        $engine = (string) (data_get($server->meta, 'cache_service') ?: 'redis');
        $config = DedicatedCacheServerProvisionConfig::fromServer($server, $engine);
        $port = ServerCacheService::defaultPortFor($config->engine);

        Log::info('server.credential_share.viewed', [
            'server_id' => $server->id,
            'share_id' => $share->id,
            'kind' => $share->kind,
            'views_remaining' => $share->fresh()?->views_remaining,
            'ip' => $request->ip(),
        ]);

        return view('server-credential-share', [
            'server' => $server,
            'engine' => $config->engine,
            'host' => $server->ip_address ?: '',
            'port' => $port,
            'requiresPassword' => $config->requirePassword,
            'password' => $config->password,
            'remoteAccess' => $config->remoteAccess,
            'share' => $share,
        ]);
    }
}
