<?php

namespace App\Http\Controllers;

use App\Support\UserAgentParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    /**
     * List the current user's sessions (for display). Only non-sensitive fields.
     * Used by ProfileController; not a standalone page.
     *
     * @return array<int, array{id: string, ip_address: string|null, user_agent: string|null, last_activity: int, device_label: string, is_current: bool}>
     */
    public static function listSessionsForUser(int|string $userId, string $currentSessionId): array
    {
        $table = config('session.table', 'sessions');

        $rows = DB::table($table)
            ->where('user_id', $userId)
            ->select('id', 'ip_address', 'user_agent', 'last_activity')
            ->orderByDesc('last_activity')
            ->get();

        return $rows->map(function ($row) use ($currentSessionId) {
            return [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_activity' => $row->last_activity,
                'device_label' => UserAgentParser::parse($row->user_agent),
                'is_current' => $row->id === $currentSessionId,
            ];
        })->all();
    }

    /**
     * Revoke a single session. Only the session owner can revoke.
     */
    public function revoke(Request $request, string $sessionId): RedirectResponse
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            abort(403);
        }

        $table = config('session.table', 'sessions');
        $currentId = $request->session()->getId();

        if ($sessionId === $currentId) {
            return redirect()->route('profile.edit')->with('error', __('You cannot revoke your current session.'));
        }

        $deleted = DB::table($table)
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->delete();

        if ($deleted) {
            return redirect()->route('profile.edit')->with('status', 'session-revoked');
        }

        return redirect()->route('profile.edit')->with('error', __('Session not found or already revoked.'));
    }

    /**
     * Revoke all other sessions (every session for this user except the current one).
     */
    public function revokeOthers(Request $request): RedirectResponse
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            abort(403);
        }

        $table = config('session.table', 'sessions');
        $currentId = $request->session()->getId();

        DB::table($table)
            ->where('user_id', $userId)
            ->where('id', '!=', $currentId)
            ->delete();

        return redirect()->route('profile.edit')->with('status', 'sessions-revoked');
    }
}
