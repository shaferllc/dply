<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Servers\ServerFileBrowserAuditLogger;
use App\Services\Servers\ServerFileBrowserRemoteReader;
use App\Support\Servers\FileBrowserPathPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Session-authed file download for the server workspace file browser.
 * Must be a plain GET route — returning StreamedResponse from a Livewire
 * action corrupts the page with raw file bytes + JSON.
 */
final class ServerWorkspaceFileDownloadController extends Controller
{
    public function __invoke(Request $request, Server $server): StreamedResponse|Response
    {
        if (! Feature::active('workspace.files')) {
            abort(404);
        }

        Gate::authorize('view', $server);

        $user = Auth::user();
        $org = $user?->currentOrganization();
        if ($org !== null && $user !== null && $org->userIsDeployer($user)) {
            abort(403, __('Deployer role does not have access to the server file browser.'));
        }

        try {
            $target = FileBrowserPathPolicy::normalize((string) $request->query('path', ''));
        } catch (\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        }

        $viewAsRoot = $request->boolean('root')
            && $user !== null
            && $org !== null
            && $org->hasAdminAccess($user);

        $deploy = trim((string) ($server->ssh_user ?? ''));
        $login = $viewAsRoot ? 'root' : ($deploy !== '' ? $deploy : 'root');

        $reader = app(ServerFileBrowserRemoteReader::class);
        $cap = (int) config('server_file_browser.download_max_bytes', 26_214_400);

        $read = $reader->read($server, $target, 0, $login);
        if ($read->size > $cap) {
            abort(413, __('File is larger than the download cap; use Manage → Run instead.'));
        }

        app(ServerFileBrowserAuditLogger::class)->recordDownload(
            $server->organization,
            $user,
            $server,
            null,
            $target,
            $read->size,
            $read->sha256,
            $login,
        );

        return new StreamedResponse(
            function () use ($reader, $server, $target, $cap, $login): void {
                $reader->streamDownload($server, $target, function (string $chunk): void {
                    echo $chunk;
                    @ob_flush();
                    @flush();
                }, $cap, $login);
            },
            200,
            [
                'Content-Type' => $read->mime ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.basename($target).'"',
                'Cache-Control' => 'no-store, max-age=0',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }
}
