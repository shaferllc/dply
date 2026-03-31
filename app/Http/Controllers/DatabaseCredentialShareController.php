<?php

namespace App\Http\Controllers;

use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseCredentialShare;
use App\Services\Servers\ServerDatabaseAuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatabaseCredentialShareController extends Controller
{
    public function show(Request $request, string $token, ServerDatabaseAuditLogger $auditLogger): View
    {
        $share = ServerDatabaseCredentialShare::query()
            ->where('token', $token)
            ->with(['serverDatabase.server'])
            ->firstOrFail();

        if ($share->isExpired()) {
            abort(410, __('This link has expired.'));
        }

        if ($share->isExhausted()) {
            abort(410, __('This link has already been used.'));
        }

        $db = $share->serverDatabase;
        $server = $db->server;

        $org = $server->organization;
        if ($org && ! $org->allowsDatabaseCredentialShares()) {
            abort(403, __('Public credential share links are disabled for this organization.'));
        }

        $share->decrement('views_remaining');

        $auditLogger->record(
            $server,
            ServerDatabaseAuditEvent::EVENT_CREDENTIAL_SHARE_VIEWED,
            [
                'server_database_id' => $db->id,
                'share_id' => $share->id,
                'views_remaining' => $share->fresh()->views_remaining,
            ],
            null,
            $request->ip()
        );

        return view('database-credential-share', [
            'database' => $db,
            'server' => $server,
            'share' => $share,
        ]);
    }
}
