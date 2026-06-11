<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Services\Servers\PreparedQuickDownload;
use App\Services\Servers\QuickDownloadStreamer;
use App\Services\Servers\QuickDownloadTooLargeException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Live "quick download": stream a fresh database dump or site artifact (files,
 * .env, vhost, logs, full home dir, or a combined bundle) straight from the
 * server to the browser — no S3, no control-plane persistence.
 *
 * Plain GET routes on purpose: returning a StreamedResponse from a Livewire
 * action corrupts the page with raw bytes + JSON (same reason as
 * {@see \App\Http\Controllers\Sites\SiteFileDownloadController}).
 */
final class QuickDownloadController extends Controller
{
    public function databaseDump(Server $server, ServerDatabase $database, QuickDownloadStreamer $streamer): StreamedResponse|Response
    {
        $this->guard($server);

        if ((string) $database->server_id !== (string) $server->id) {
            abort(404);
        }

        return $this->respond(fn () => $streamer->prepareDatabaseDump($database), $streamer);
    }

    public function siteArtifact(Server $server, Site $site, string $artifact, QuickDownloadStreamer $streamer): StreamedResponse|Response
    {
        $this->guard($server);

        if ((string) $site->server_id !== (string) $server->id) {
            abort(404);
        }

        if (! in_array($artifact, QuickDownloadStreamer::SITE_ARTIFACTS, true)) {
            abort(404);
        }

        return $this->respond(fn () => $streamer->prepareSiteArtifact($site, $artifact), $streamer);
    }

    private function guard(Server $server): void
    {
        Gate::authorize('update', $server);

        $org = Auth::user()?->currentOrganization();
        if ($org === null || ! Feature::for($org)->active('workspace.backups')) {
            abort(404);
        }
    }

    /**
     * @param  callable():PreparedQuickDownload  $prepare
     */
    private function respond(callable $prepare, QuickDownloadStreamer $streamer): StreamedResponse
    {
        try {
            $prepared = $prepare();
        } catch (QuickDownloadTooLargeException $e) {
            abort(413, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return new StreamedResponse(
            function () use ($streamer, $prepared): void {
                $streamer->stream($prepared);
            },
            200,
            [
                'Content-Type' => $prepared->mime,
                'Content-Length' => (string) $prepared->bytes,
                'Content-Disposition' => 'attachment; filename="'.addslashes($prepared->filename).'"',
                'Cache-Control' => 'no-store, max-age=0',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }
}
