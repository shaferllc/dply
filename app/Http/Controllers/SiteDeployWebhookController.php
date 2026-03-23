<?php

namespace App\Http\Controllers;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\WebhookDeliveryLog;
use App\Services\Sites\SiteWebhookSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteDeployWebhookController extends Controller
{
    public function __invoke(Request $request, Site $site, SiteWebhookSignatureValidator $validator): JsonResponse
    {
        $result = $validator->validateForWebhook($request, $site);
        WebhookDeliveryLog::query()->create([
            'site_id' => $site->id,
            'request_ip' => $request->ip(),
            'http_status' => $result['status'],
            'outcome' => $result['outcome'],
            'detail' => $result['detail'],
        ]);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        $site->loadMissing('organization');
        if ($site->organization) {
            audit_log($site->organization, null, 'site.deploy.webhook_queued', $site, null, [
                'site' => $site->name,
            ]);
        }

        RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_WEBHOOK);

        return response()->json(['message' => $result['message']], 202);
    }
}
