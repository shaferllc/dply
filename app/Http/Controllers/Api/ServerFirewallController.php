<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FirewallRuleTemplate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerFirewallSnapshot;
use App\Services\Servers\FirewallDriftAnalyzer;
use App\Services\Servers\FirewallMaintenanceGate;
use App\Services\Servers\FirewallRuleStateHasher;
use App\Services\Servers\FirewallRuleTemplateApplicator;
use App\Services\Servers\FirewallTerraformExporter;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallImportExport;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\ServerFirewallSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

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

    public function preview(Request $request, Server $server, ServerFirewallProvisioner $provisioner): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        $lines = $provisioner->previewApplyCommands($server);

        return response()->json(['data' => ['commands' => $lines]]);
    }

    public function drift(
        Request $request,
        Server $server,
        ServerFirewallProvisioner $provisioner,
        FirewallDriftAnalyzer $drift
    ): JsonResponse {
        $this->assertServerOrg($server, $this->organization($request));

        $status = $provisioner->status($server);
        $enabled = $server->firewallRules()->where('enabled', true)->orderBy('sort_order')->get();
        $result = $drift->analyze($server, $status, $enabled);

        return response()->json(['data' => array_merge($result, ['ufw_status_excerpt' => Str::limit($status, 8000)])]);
    }

    public function apply(
        Request $request,
        Server $server,
        ServerFirewallProvisioner $provisioner,
        FirewallMaintenanceGate $maintenance,
        ServerFirewallApplyRecorder $recorder,
    ): JsonResponse {
        $organization = $this->organization($request);
        $this->assertServerOrg($server, $organization);

        $validated = $request->validate([
            'acknowledge_ssh_lockout_risk' => 'sometimes|boolean',
        ]);

        $token = $request->attributes->get('api_token');
        $user = $request->user();

        if ($reason = $maintenance->blockedReason($server)) {
            return response()->json(['message' => $reason, 'code' => 'maintenance'], 423);
        }

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

    public function terraform(Request $request, Server $server, FirewallTerraformExporter $exporter): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        return response()->json([
            'data' => [
                'hcl' => $exporter->toHcl($server),
                'note' => 'Documentation hand-off only; UFW on the host remains authoritative.',
            ],
        ]);
    }

    public function iptables(Request $request, Server $server, ServerFirewallProvisioner $provisioner): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        if (! config('server_firewall.danger_zone.iptables_counters_enabled', false)) {
            return response()->json([
                'message' => 'iptables snapshot is disabled. Set SERVER_FIREWALL_IPTABLES_COUNTERS=true.',
                'code' => 'iptables_disabled',
            ], 403);
        }

        try {
            $out = $provisioner->iptablesCountersSnapshot($server);

            return response()->json([
                'data' => [
                    'output' => $out,
                    'note' => 'Read-only first rows of iptables -L -n -v; requires sudo on host.',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to read iptables.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request, Server $server, ServerFirewallImportExport $io): Response
    {
        $this->assertServerOrg($server, $this->organization($request));

        $json = $io->exportJson($server);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="firewall-'.$server->id.'.json"',
        ]);
    }

    public function import(Request $request, Server $server, ServerFirewallImportExport $io): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        $validated = $request->validate([
            'json' => 'required|string|max:512000',
            'replace' => 'sometimes|boolean',
        ]);

        try {
            $n = $io->importJson(
                $server,
                $validated['json'],
                null,
                (bool) ($validated['replace'] ?? true),
                $request->attributes->get('api_token')
            );

            return response()->json([
                'message' => 'Import completed.',
                'imported_rules' => $n,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Import failed.',
                'error' => $e->getMessage(),
            ], 422);
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

    public function createSnapshot(Request $request, Server $server, ServerFirewallSnapshotService $snapshots): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));
        $validated = $request->validate(['label' => 'nullable|string|max:200']);

        $snap = $snapshots->create($server, null, $validated['label'] ?? null, $request->attributes->get('api_token'));

        return response()->json([
            'message' => 'Snapshot created.',
            'data' => [
                'id' => $snap->id,
                'label' => $snap->label,
                'created_at' => $snap->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function restoreSnapshot(
        Request $request,
        Server $server,
        string $snapshot,
        ServerFirewallSnapshotService $snapshots
    ): JsonResponse {
        $organization = $this->organization($request);
        $this->assertServerOrg($server, $organization);

        $s = ServerFirewallSnapshot::query()
            ->where('server_id', $server->id)
            ->whereKey($snapshot)
            ->firstOrFail();

        try {
            $snapshots->restore($server, $s, null, $request->attributes->get('api_token'));

            return response()->json(['message' => 'Snapshot restored.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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
