<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FirewallRuleTemplate;
use App\Models\Organization;
use App\Models\Server;
use App\Services\Servers\FirewallRuleStateHasher;
use App\Services\Servers\FirewallRuleTemplateApplicator;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServerFirewallController extends Controller
{
    public function show(Request $request, Server $server, FirewallRuleStateHasher $hasher): JsonResponse|Response
    {
        $organization = $this->organization($request);
        $this->assertServerOrg($server, $organization);

        $server->load(['firewallRules' => fn ($q) => $q->orderBy('sort_order')]);

        $templates = FirewallRuleTemplate::query()
            ->where('organization_id', $organization->id)
            ->where(function ($q) use ($server) {
                $q->whereNull('server_id')->orWhere('server_id', $server->id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'server_id', 'updated_at']);

        $etag = '"'.$hasher->hashServerRules($server)['hash'].'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304)->withHeaders(['ETag' => $etag]);
        }

        return response()->json([
            'data' => [
                'server_id' => $server->id,
                'rules' => $server->firewallRules->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'port' => $r->port,
                    'protocol' => $r->protocol,
                    'source' => $r->source,
                    'action' => $r->action,
                    'enabled' => $r->enabled,
                    'sort_order' => $r->sort_order,
                    'profile' => $r->profile,
                    'tags' => $r->tags,
                    'runbook_url' => $r->runbook_url,
                    'site_id' => $r->site_id,
                ]),
                'templates' => $templates,
                'bundled_template_keys' => array_keys(config('server_firewall.bundled_templates', [])),
            ],
        ])->withHeaders(['ETag' => $etag]);
    }

    public function apply(
        Request $request,
        Server $server,
        ServerFirewallProvisioner $provisioner,
        ServerFirewallApplyRecorder $recorder,
    ): JsonResponse {
        $organization = $this->organization($request);
        $this->assertServerOrg($server, $organization);

        $validated = $request->validate([
            'acknowledge_ssh_lockout_risk' => 'sometimes|boolean',
        ]);

        $token = $request->attributes->get('api_token');
        $user = $request->user();

        if ($provisioner->sshAccessNotExplicitlyAllowed($server)
            && empty($validated['acknowledge_ssh_lockout_risk'])) {
            return response()->json([
                'message' => 'SSH lockout risk: pass acknowledge_ssh_lockout_risk=true after review.',
                'code' => 'ssh_lockout_ack_required',
            ], 422);
        }

        try {
            $out = $provisioner->apply($server);
            $recorder->recordSuccess($server, $user, $token, $out, 'api');

            return response()->json([
                'message' => 'Firewall rules applied.',
                'output' => $out,
            ]);
        } catch (\Throwable $e) {
            $recorder->recordFailure($server, $user, $token, $e->getMessage(), 'api');

            return response()->json([
                'message' => 'Apply failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function applyBundled(
        Request $request,
        Server $server,
        string $key,
        FirewallRuleTemplateApplicator $applicator
    ): JsonResponse {
        $this->assertServerOrg($server, $this->organization($request));

        try {
            $n = $applicator->applyBundled($server, $key, null, $request->attributes->get('api_token'));

            return response()->json([
                'message' => 'Bundled template applied.',
                'rules_created' => $n,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function applyTemplate(
        Request $request,
        Server $server,
        FirewallRuleTemplate $template,
        FirewallRuleTemplateApplicator $applicator
    ): JsonResponse {
        $organization = $this->organization($request);
        $this->assertServerOrg($server, $organization);

        if ($template->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $n = $applicator->applyDatabaseTemplate($server, $template, null, $request->attributes->get('api_token'));

            return response()->json([
                'message' => 'Template applied.',
                'rules_created' => $n,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('api_organization');
    }

    private function assertServerOrg(Server $server, Organization $organization): void
    {
        abort_if($server->organization_id !== $organization->id, 403);
    }
}
