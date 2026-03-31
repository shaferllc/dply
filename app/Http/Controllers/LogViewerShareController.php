<?php

namespace App\Http\Controllers;

use App\Models\LogViewerShare;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogViewerShareController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $share = LogViewerShare::query()
            ->where('token', $token)
            ->with('server')
            ->firstOrFail();

        if ($share->isExpired()) {
            abort(410, __('This share link has expired.'));
        }

        $this->authorize('view', $share->server);

        return view('log-viewer-share', [
            'share' => $share,
        ]);
    }
}
