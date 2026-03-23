<?php

namespace App\Http\Controllers;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteDeployWebhookController extends Controller
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        $secret = $site->webhook_secret;
        if ($secret === null || $secret === '') {
            return response()->json(['message' => 'Webhook secret not configured.'], 400);
        }

        $payload = $request->getContent();
        $sig = (string) $request->header('X-Dply-Signature', '');
        $expected = hash_hmac('sha256', $payload, $secret);
        if ($sig === '' || ! hash_equals('sha256='.$expected, $sig)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_WEBHOOK);

        return response()->json(['message' => 'Deployment queued.'], 202);
    }
}
