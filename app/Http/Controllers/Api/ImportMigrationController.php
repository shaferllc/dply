<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only HTTP API for inventory-import migrations. Lets external monitoring
 * tools (Slack alerts, on-call dashboards, status-page integrations) poll
 * migration state without screen-scraping the Livewire UI.
 *
 * Scoped to the api_organization the bearer token belongs to.
 */
class ImportMigrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        $query = ImportServerMigration::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('created_at');

        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->boolean('active')) {
            $query->whereNotIn('status', [
                ImportServerMigration::STATUS_COMPLETED,
                ImportServerMigration::STATUS_PARTIAL,
                ImportServerMigration::STATUS_ABORTED,
                ImportServerMigration::STATUS_EXPIRED,
            ]);
        }

        $migrations = $query->with(['steps:id,import_server_migration_id,status'])->get();

        return response()->json([
            'data' => $migrations->map(fn (ImportServerMigration $m) => $this->summary($m))->all(),
        ]);
    }

    public function show(Request $request, ImportServerMigration $migration): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        if ($migration->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $migration->load(['siteMigrations.steps', 'steps']);

        return response()->json([
            'data' => array_merge($this->summary($migration), [
                'steps' => $migration->steps->whereNull('import_site_migration_id')->values()->map(fn (ImportMigrationStep $s) => $this->stepRow($s))->all(),
                'sites' => $migration->siteMigrations->map(fn ($child) => [
                    'id' => $child->id,
                    'source_site_id' => $child->source_site_id,
                    'domain' => $child->domain,
                    'site_type' => $child->site_type,
                    'status' => $child->status,
                    'ssl_strategy' => $child->ssl_strategy,
                    'failure_summary' => $child->failure_summary,
                    'cutover_started_at' => $child->cutover_started_at?->toIso8601String(),
                    'cutover_completed_at' => $child->cutover_completed_at?->toIso8601String(),
                    'steps' => $child->steps->map(fn (ImportMigrationStep $s) => $this->stepRow($s))->values()->all(),
                ])->all(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(ImportServerMigration $migration): array
    {
        $counts = $migration->steps->countBy('status');

        return [
            'id' => $migration->id,
            'source' => $migration->source,
            'source_server_id' => $migration->source_server_id,
            'target_server_id' => $migration->target_server_id,
            'status' => $migration->status,
            'ssh_key_pushed_at' => $migration->ssh_key_pushed_at?->toIso8601String(),
            'ssh_key_revoked_at' => $migration->ssh_key_revoked_at?->toIso8601String(),
            'started_at' => $migration->started_at?->toIso8601String(),
            'completed_at' => $migration->completed_at?->toIso8601String(),
            'created_at' => $migration->created_at?->toIso8601String(),
            'failure_summary' => $migration->failure_summary,
            'step_counts' => [
                'succeeded' => (int) ($counts->get('succeeded', 0)),
                'failed' => (int) ($counts->get('failed', 0)),
                'running' => (int) ($counts->get('running', 0)),
                'pending' => (int) ($counts->get('pending', 0)),
                'skipped' => (int) ($counts->get('skipped', 0)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stepRow(ImportMigrationStep $step): array
    {
        return [
            'id' => $step->id,
            'sequence' => $step->sequence,
            'step_key' => $step->step_key,
            'status' => $step->status,
            'attempts' => $step->attempts,
            'started_at' => $step->started_at?->toIso8601String(),
            'finished_at' => $step->finished_at?->toIso8601String(),
            'error_message' => $step->error_message,
        ];
    }
}
