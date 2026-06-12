<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuickDownload;
use App\Services\Servers\QuickDownloadBuildStager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a staged quick-download from the operator-managed download bucket to
 * the browser, then deletes it on a clean finish (single-use). Reaching it needs
 * BOTH a valid signed URL (handed out by the in-app inbox + email) AND an
 * authenticated session authorized on the server — so a leaked link can't, on its
 * own, exfiltrate a .env or database dump.
 *
 * Plain signed GET route on purpose: returning a StreamedResponse from a Livewire
 * action corrupts the page with raw bytes (same reason as
 * {@see \App\Http\Controllers\Sites\SiteFileDownloadController}). The bytes flow
 * control-plane → browser; the 250MB quick-download cap keeps that tolerable.
 */
final class QuickDownloadController extends Controller
{
    public function fetch(QuickDownload $quickDownload, QuickDownloadBuildStager $stager): StreamedResponse|Response
    {
        $server = $quickDownload->server;
        if ($server === null) {
            abort(404);
        }

        Gate::authorize('update', $server);

        $org = Auth::user()?->currentOrganization();
        if ($org === null || ! Feature::for($org)->active('workspace.backups')) {
            abort(404);
        }

        if (! $quickDownload->isDownloadable()) {
            // Consumed, expired, still building, or failed — send them back to
            // re-queue rather than dumping a stale or missing file.
            abort(410, __('This download is no longer available — request it again.'));
        }

        try {
            $body = $stager->openReadStream($quickDownload);
        } catch (\Throwable $e) {
            abort(410, $e->getMessage());
        }

        $expectedBytes = (int) ($quickDownload->bytes ?? 0);

        return new StreamedResponse(
            function () use ($body, $quickDownload, $stager, $expectedBytes): void {
                $sent = 0;
                while (! $body->eof()) {
                    $chunk = $body->read(1_048_576);
                    if ($chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $sent += strlen($chunk);
                    @ob_flush();
                    @flush();

                    // Client went away mid-transfer: leave the object intact so they
                    // can retry (or the sweeper clears it at the 4h mark).
                    if (connection_aborted() !== 0) {
                        return;
                    }
                }

                // Only consume (delete + mark) on a verified-complete download.
                if (connection_aborted() === 0 && ($expectedBytes === 0 || $sent >= $expectedBytes)) {
                    $stager->consume($quickDownload);
                }
            },
            200,
            array_filter([
                'Content-Type' => $quickDownload->mime ?: 'application/octet-stream',
                'Content-Length' => $expectedBytes > 0 ? (string) $expectedBytes : null,
                'Content-Disposition' => 'attachment; filename="'.addslashes((string) ($quickDownload->filename ?: 'download')).'"',
                'Cache-Control' => 'no-store, max-age=0',
                'X-Accel-Buffering' => 'no',
            ]),
        );
    }
}
