<?php

declare(strict_types=1);

namespace App\Services\OpsCopilot;

use App\Models\DeployIntelligenceAlert;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Collection;

/**
 * Assembles cross-engine deploy context for Ops Copilot — latest failure,
 * log excerpt, repo config snapshot, intelligence alerts, and server runbook
 * commands when available.
 */
final class OpsCopilotContextBuilder
{
    public function __construct(
        private readonly OpsCopilotAdvisor $advisor,
    ) {}

    /**
     * Sites with a recent failed BYO deploy or failed Edge build.
     *
     * @return Collection<int, array{id: string, name: string, product: string, failed_at: string|null}>
     */
    public function candidateSites(Organization $organization): Collection
    {
        $serverIds = Server::query()
            ->where('organization_id', $organization->id)
            ->pluck('id');

        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->get(['id', 'name', 'slug', 'server_id', 'runtime', 'edge_backend', 'container_backend', 'meta']);

        if ($sites->isEmpty()) {
            return collect();
        }

        $candidates = [];

        foreach ($sites as $site) {
            $failure = $this->latestFailureForSite($site);
            if ($failure === null) {
                continue;
            }

            $candidates[(string) $site->id] = [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'product' => $this->productLabel($site),
                'failed_at' => $failure['failed_at'],
            ];
        }

        return collect($candidates)
            ->sortByDesc(fn (array $row): ?string => $row['failed_at'])
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(Organization $organization, Site $site): ?array
    {
        if ($site->organization_id !== $organization->id) {
            return null;
        }

        $failure = $this->latestFailureForSite($site);
        if ($failure === null) {
            return null;
        }

        $logHaystack = trim(($failure['summary'] ?? '')."\n".($failure['log_excerpt'] ?? ''));
        $suggestions = $this->advisor->suggest($logHaystack);

        $alerts = DeployIntelligenceAlert::query()
            ->where('organization_id', $organization->id)
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->orderByDesc('severity')
            ->limit(5)
            ->get(['id', 'rule_key', 'title', 'summary', 'severity']);

        $savedCommands = [];
        if ($site->server_id !== null) {
            $savedCommands = ServerRecipe::query()
                ->where('server_id', $site->server_id)
                ->orderBy('name')
                ->limit(8)
                ->pluck('name')
                ->all();
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $repoConfig = $failure['repo_config'] ?? null;
        if ($repoConfig === null && is_array($meta['repo_config'] ?? null)) {
            $repoConfig = $meta['repo_config'];
        }

        return [
            'site' => [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'product' => $this->productLabel($site),
                'runtime' => is_string($site->runtime) ? $site->runtime : null,
                'server_id' => $site->server_id !== null ? (string) $site->server_id : null,
            ],
            'failure' => $failure,
            'repo_config' => $repoConfig,
            'deploy_settings' => [
                'build_command' => $site->build_command,
                'deploy_command' => $site->deploy_command,
                'web_directory' => $site->web_directory,
            ],
            'intelligence_alerts' => $alerts->map(fn (DeployIntelligenceAlert $alert): array => [
                'id' => (string) $alert->id,
                'rule_key' => (string) $alert->rule_key,
                'title' => (string) $alert->title,
                'summary' => (string) ($alert->summary ?? ''),
                'severity' => (string) $alert->severity,
            ])->all(),
            'saved_commands' => $savedCommands,
            'suggestions' => array_map(
                static fn (OpsCopilotSuggestion $suggestion): array => $suggestion->toArray(),
                $suggestions,
            ),
            'llm_enabled' => ai_llm_active($organization)
                && (bool) config('dply_ai.features.ops_copilot', true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestFailureForSite(Site $site): ?array
    {
        $byo = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', SiteDeployment::STATUS_FAILED)
            ->orderByDesc('finished_at')
            ->first(['id', 'status', 'log_output', 'phase_results', 'exit_code', 'finished_at', 'started_at']);

        $edge = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_FAILED)
            ->orderByDesc('failed_at')
            ->first(['id', 'status', 'failure_reason', 'build_log_path', 'repo_config', 'failed_at', 'created_at']);

        $byoAt = $byo?->finished_at;
        $edgeAt = $edge?->failed_at ?? $edge?->created_at;

        if ($byo === null && $edge === null) {
            return null;
        }

        $useEdge = $edge !== null && ($byoAt === null || ($edgeAt !== null && $edgeAt->gt($byoAt)));

        if ($useEdge && $edge !== null) {
            $log = $edge->readBuildLog($site);
            $excerpt = $this->tailExcerpt((string) ($log ?? ''));

            return [
                'source' => 'edge_deploy',
                'deployment_id' => (string) $edge->id,
                'summary' => is_string($edge->failure_reason) ? $edge->failure_reason : '',
                'log_excerpt' => $excerpt,
                'exit_code' => null,
                'failed_at' => $edgeAt?->toIso8601String(),
                'repo_config' => is_array($edge->repo_config) ? $edge->repo_config : null,
            ];
        }

        if ($byo === null) {
            return null;
        }

        $log = (string) ($byo->log_output ?? '');
        $phaseSnippet = $this->failedPhaseSnippet(is_array($byo->phase_results) ? $byo->phase_results : []);

        return [
            'source' => 'byo_deploy',
            'deployment_id' => (string) $byo->id,
            'summary' => $phaseSnippet,
            'log_excerpt' => $this->tailExcerpt($log),
            'exit_code' => $byo->exit_code,
            'failed_at' => $byoAt?->toIso8601String(),
            'repo_config' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $phaseResults
     */
    private function failedPhaseSnippet(array $phaseResults): string
    {
        foreach ($phaseResults as $phase => $steps) {
            if (! is_array($steps)) {
                continue;
            }
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                if (($step['ok'] ?? false) === true) {
                    continue;
                }
                $label = is_string($step['label'] ?? null) ? $step['label'] : (string) $phase;
                $output = is_string($step['output'] ?? null) ? trim($step['output']) : '';

                return $output !== '' ? $label.': '.$output : $label.' failed';
            }
        }

        return 'Deploy failed';
    }

    private function tailExcerpt(string $log): string
    {
        $log = trim($log);
        if ($log === '') {
            return '';
        }

        $max = (int) config('dply_ops_copilot.log_excerpt_bytes', 24_000);
        if (strlen($log) <= $max) {
            return $log;
        }

        return '…'.substr($log, -$max);
    }

    private function productLabel(Site $site): string
    {
        if ($site->usesEdgeRuntime()) {
            return 'edge';
        }
        if ($site->usesContainerRuntime()) {
            return 'cloud';
        }
        if ($site->usesFunctionsRuntime()) {
            return 'serverless';
        }

        return 'byo';
    }
}
