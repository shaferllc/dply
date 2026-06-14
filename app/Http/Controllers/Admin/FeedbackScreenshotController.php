<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedbackReport;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a feedback report's screenshot from the private feedback disk to
 * platform admins only. The bytes never live on a public disk — this authorized
 * proxy is the only read path.
 */
class FeedbackScreenshotController extends Controller
{
    public function __invoke(FeedbackReport $report): Response|StreamedResponse
    {
        Gate::authorize('viewPlatformAdmin');

        abort_if($report->screenshot_path === null, 404);

        $disk = Storage::disk(config('feedback.disk'));

        abort_unless($disk->exists($report->screenshot_path), 404);

        return $disk->response($report->screenshot_path, null, [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
