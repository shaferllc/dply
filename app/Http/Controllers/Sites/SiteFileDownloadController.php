<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerFileBrowserAuditLogger;
use App\Services\Servers\ServerFileBrowserRemoteReader;
use App\Support\Servers\FileBrowserPathPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Session-authed file download for the site file browser (same Livewire
 * StreamedResponse pitfall as {@see ServerWorkspaceFileDownloadController}).
 */
final class SiteFileDownloadController extends Controller
{
    public function __invoke(Request $request, Server $server, Site $site): StreamedResponse|Response
    {
        Gate::authorize('view', $site);

        if ((string) $site->server_id !== (string) $server->id) {
            abort(404);
        }

        try {
            $target = FileBrowserPathPolicy::normalize((string) $request->query('path', ''));
        } catch (\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        }

        $deploy = trim((string) ($server->ssh_user ?? ''));
        $login = trim((string) $site->effectiveSystemUser($server)) ?: ($deploy !== '' ? $deploy : 'root');

        $reader = app(ServerFileBrowserRemoteReader::class);
        $cap = (int) config('server_file_browser.download_max_bytes', 26_214_400);

        $read = $reader->read($server, $target, 0, $login);
        if ($read->size > $cap) {
            abort(413, __('File is larger than the download cap.'));
        }

        app(ServerFileBrowserAuditLogger::class)->recordDownload(
            $site->organization,
            Auth::user(),
            $server,
            $site,
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
