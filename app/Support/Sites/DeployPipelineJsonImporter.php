<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use InvalidArgumentException;

final class DeployPipelineJsonImporter
{
    private const MAX_STEPS = 100;

    private const MAX_HOOKS = 50;

    /**
     * @return array{steps: int, hooks: int}
     */
    public function apply(
        Site $site,
        SiteDeployPipeline $pipeline,
        string $json,
        bool $applyRollout = false,
    ): array {
        $data = $this->parse($json);
        $body = $data['pipeline'] ?? null;
        if (! is_array($body)) {
            throw new InvalidArgumentException(__('Pipeline JSON must include a pipeline object.'));
        }

        $steps = $body['steps'] ?? [];
        $hooks = $body['hooks'] ?? [];
        if (! is_array($steps) || ! is_array($hooks)) {
            throw new InvalidArgumentException(__('Invalid steps or hooks in pipeline JSON.'));
        }
        if (count($steps) > self::MAX_STEPS) {
            throw new InvalidArgumentException(__('Too many steps (max :max).', ['max' => self::MAX_STEPS]));
        }
        if (count($hooks) > self::MAX_HOOKS) {
            throw new InvalidArgumentException(__('Too many hooks (max :max).', ['max' => self::MAX_HOOKS]));
        }

        $pipeline->hooks()->delete();
        $pipeline->steps()->delete();

        $sortToStepId = [];
        foreach ($this->normalizedSteps($steps) as $index => $step) {
            $created = $pipeline->steps()->create([
                'site_id' => $site->id,
                'sort_order' => ($index + 1) * 10,
                'step_type' => $step['step_type'],
                'phase' => $step['phase'],
                'custom_command' => $step['custom_command'],
                'timeout_seconds' => $step['timeout_seconds'],
            ]);
            $sortToStepId[$step['sort_order']] = (string) $created->id;
            $sortToStepId[($index + 1) * 10] = (string) $created->id;
        }

        $hookCount = 0;
        foreach ($this->normalizedHooks($hooks) as $hook) {
            $anchorStepId = null;
            if ($hook['anchor'] === SiteDeployHook::ANCHOR_AFTER_STEP) {
                $sort = $hook['after_step_sort_order'] ?? null;
                if ($sort !== null && isset($sortToStepId[(int) $sort])) {
                    $anchorStepId = $sortToStepId[(int) $sort];
                }
            }

            $pipeline->hooks()->create([
                'site_id' => $site->id,
                'sort_order' => $hook['sort_order'],
                'phase' => $hook['phase'],
                'hook_kind' => $hook['hook_kind'],
                'anchor' => $hook['anchor'],
                'anchor_step_id' => $anchorStepId,
                'label' => $hook['label'],
                'script' => $hook['script'] ?? null,
                'webhook_url' => $hook['webhook_url'] ?? null,
                'notification_channel_id' => $hook['notification_channel_id'] ?? null,
                'notification_event' => $hook['notification_event'] ?? null,
                'timeout_seconds' => $hook['timeout_seconds'],
            ]);
            $hookCount++;
        }

        $pipeline->update([
            'clone_script' => isset($body['clone_script']) ? (string) $body['clone_script'] : null,
            'activate_script' => isset($body['activate_script']) ? (string) $body['activate_script'] : null,
            'deploy_branches' => is_array($body['deploy_branches'] ?? null) ? array_values($body['deploy_branches']) : [],
        ]);

        if ($applyRollout && isset($data['rollout']) && is_array($data['rollout'])) {
            $this->applyRollout($site, $data['rollout']);
        }

        return [
            'steps' => count($steps),
            'hooks' => $hookCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            throw new InvalidArgumentException(__('Pipeline JSON is empty.'));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException(__('Invalid JSON: :message', ['message' => $e->getMessage()]));
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException(__('Pipeline JSON must be an object.'));
        }

        $version = (int) ($data['version'] ?? 0);
        if ($version !== DeployPipelineJsonExporter::VERSION) {
            throw new InvalidArgumentException(__('Unsupported pipeline JSON version.'));
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public function previewLines(string $json, bool $applyRollout): array
    {
        $data = $this->parse($json);
        $pipeline = $data['pipeline'] ?? [];
        $steps = is_array($pipeline['steps'] ?? null) ? count($pipeline['steps']) : 0;
        $hooks = is_array($pipeline['hooks'] ?? null) ? count($pipeline['hooks']) : 0;
        $lines = [
            __('Replace all steps and hooks on the target pipeline'),
            __('Import :steps step(s) and :hooks hook(s)', ['steps' => $steps, 'hooks' => $hooks]),
        ];
        if ($applyRollout && isset($data['rollout']) && is_array($data['rollout'])) {
            $strategy = (string) ($data['rollout']['deploy_strategy'] ?? 'simple');
            $lines[] = __('Apply rollout → :strategy', [
                'strategy' => $strategy === 'atomic' ? __('Zero downtime') : __('Simple'),
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed> $steps
     * @return list<array{step_type: string, phase: string, custom_command: ?string, timeout_seconds: int, sort_order: int}>
     */
    private function normalizedSteps(array $steps): array
    {
        $allowedTypes = array_keys(SiteDeployStep::typeLabels());
        $normalized = [];

        foreach ($steps as $step) {
            if (! is_array($step) || ! isset($step['step_type'])) {
                throw new InvalidArgumentException(__('Each step must have a step_type.'));
            }
            $type = (string) $step['step_type'];
            if (! in_array($type, $allowedTypes, true)) {
                throw new InvalidArgumentException(__('Unknown step type: :type', ['type' => $type]));
            }
            $phase = (string) ($step['phase'] ?? SiteDeployStep::PHASE_BUILD);
            if (! in_array($phase, SiteDeployStep::userPhases(), true)) {
                throw new InvalidArgumentException(__('Invalid step phase: :phase', ['phase' => $phase]));
            }

            $normalized[] = [
                'step_type' => $type,
                'phase' => $phase,
                'custom_command' => isset($step['custom_command']) && trim((string) $step['custom_command']) !== ''
                    ? trim((string) $step['custom_command'])
                    : null,
                'timeout_seconds' => max(30, min(3600, (int) ($step['timeout_seconds'] ?? 900))),
                'sort_order' => (int) ($step['sort_order'] ?? (count($normalized) + 1) * 10),
            ];
        }

        usort($normalized, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);

        return $normalized;
    }

    /**
     * @param  array<string, mixed> $hooks
     * @return list<array<string, mixed>>
     */
    private function normalizedHooks(array $hooks): array
    {
        $normalized = [];

        foreach ($hooks as $hook) {
            if (! is_array($hook) || ! isset($hook['hook_kind'], $hook['anchor'])) {
                throw new InvalidArgumentException(__('Each hook must have hook_kind and anchor.'));
            }
            $kind = (string) $hook['hook_kind'];
            $anchor = (string) $hook['anchor'];
            if (! in_array($kind, SiteDeployHook::kinds(), true)) {
                throw new InvalidArgumentException(__('Unknown hook kind: :kind', ['kind' => $kind]));
            }
            if (! in_array($anchor, SiteDeployHook::anchors(), true)) {
                throw new InvalidArgumentException(__('Unknown hook anchor: :anchor', ['anchor' => $anchor]));
            }

            $row = [
                'hook_kind' => $kind,
                'anchor' => $anchor,
                'phase' => (string) ($hook['phase'] ?? $anchor),
                'label' => isset($hook['label']) ? (string) $hook['label'] : null,
                'timeout_seconds' => max(30, min(3600, (int) ($hook['timeout_seconds'] ?? 120))),
                'after_step_sort_order' => isset($hook['after_step_sort_order']) ? (int) $hook['after_step_sort_order'] : null,
            ];

            if ($kind === SiteDeployHook::KIND_SHELL) {
                $row['script'] = (string) ($hook['script'] ?? '');
            }
            if ($kind === SiteDeployHook::KIND_WEBHOOK) {
                $row['webhook_url'] = (string) ($hook['webhook_url'] ?? '');
            }
            if ($kind === SiteDeployHook::KIND_NOTIFICATION) {
                $row['notification_channel_id'] = $hook['notification_channel_id'] ?? null;
                $row['notification_event'] = (string) ($hook['notification_event'] ?? SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME);
            }

            $row['sort_order'] = (int) ($hook['sort_order'] ?? (count($normalized) + 1) * 10);
            $normalized[] = $row;
        }

        usort($normalized, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);

        return $normalized;
    }

    /**
     * @param  array<string, mixed> $rollout
     */
    private function applyRollout(Site $site, array $rollout): void
    {
        $strategy = (string) ($rollout['deploy_strategy'] ?? $site->deploy_strategy ?? 'simple');
        if (! in_array($strategy, ['simple', 'atomic'], true)) {
            $strategy = 'simple';
        }

        $meta = ($site->meta );
        if (array_key_exists('deploy_health_enabled', $rollout)) {
            $meta['deploy_health_enabled'] = (bool) $rollout['deploy_health_enabled'];
        }
        if (array_key_exists('deploy_health_auto_rollback', $rollout)) {
            $meta['deploy_health_auto_rollback'] = (bool) $rollout['deploy_health_auto_rollback'];
        }
        foreach ([
            'deploy_health_path',
            'deploy_health_scheme',
            'deploy_health_host',
        ] as $key) {
            if (array_key_exists($key, $rollout)) {
                $meta[$key] = $rollout[$key];
            }
        }
        foreach ([
            'deploy_health_expect_status',
            'deploy_health_attempts',
            'deploy_health_delay_ms',
        ] as $key) {
            if (array_key_exists($key, $rollout)) {
                $meta[$key] = (int) $rollout[$key];
            }
        }
        if (array_key_exists('deploy_health_port', $rollout)) {
            $meta['deploy_health_port'] = $rollout['deploy_health_port'];
        }

        $update = [
            'deploy_strategy' => $strategy,
            'meta' => $meta,
        ];
        if (array_key_exists('releases_to_keep', $rollout)) {
            $update['releases_to_keep'] = max(1, min(50, (int) $rollout['releases_to_keep']));
        }

        $site->update($update);
    }
}
