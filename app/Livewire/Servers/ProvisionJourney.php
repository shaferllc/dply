<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProvisionJourney extends Component
{
    use InteractsWithServerWorkspace;

    protected const SCRIPT_STEP_PREFIX = '[dply-step] ';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        $this->server->refresh();

        $task = $this->provisionTask();
        $steps = $this->steps($task);
        $completedCount = collect($steps)->where('state', 'completed')->count();

        return view('livewire.servers.provision-journey', [
            'task' => $task,
            'steps' => $steps,
            'completedCount' => $completedCount,
            'totalCount' => count($steps),
            'activeStep' => collect($steps)->firstWhere('state', 'active'),
            'pendingSteps' => collect($steps)->where('state', 'pending')->values(),
            'completedSteps' => collect($steps)->where('state', 'completed')->values(),
            'failedStep' => collect($steps)->firstWhere('state', 'failed'),
        ]);
    }

    protected function provisionTask(): ?Task
    {
        $taskId = (string) ($this->server->meta['provision_task_id'] ?? '');
        if ($taskId === '') {
            return null;
        }

        return Task::query()->find($taskId);
    }

    /**
     * @return list<array{key:string,label:string,state:string,detail:?string,duration:?string}>
     */
    protected function steps(?Task $task): array
    {
        $server = $this->server;
        $scriptSteps = $this->scriptSteps($task);

        $steps = [
            ['key' => 'queued', 'label' => __('Request queued with provider')],
            ['key' => 'provisioning', 'label' => __('Provisioning server')],
            ['key' => 'ip', 'label' => __('Waiting for server IP')],
            ['key' => 'ssh', 'label' => __('Waiting for SSH')],
            ['key' => 'ready', 'label' => __('Server ready')],
        ];

        if ($scriptSteps !== []) {
            array_splice($steps, 4, 0, $scriptSteps);
        } else {
            array_splice($steps, 4, 0, [[
                'key' => 'setup',
                'label' => __('Running server setup'),
            ]]);
        }

        $activeKey = 'queued';
        $failedKey = null;
        $scriptStepKeys = array_column($scriptSteps, 'key');
        $lastSeenScriptKey = $this->lastSeenScriptStepKey($task, $scriptSteps);

        if ($server->status === Server::STATUS_ERROR || $server->setup_status === Server::SETUP_STATUS_FAILED) {
            $activeKey = $server->setup_status === Server::SETUP_STATUS_FAILED
                ? ($lastSeenScriptKey ?? ($scriptStepKeys[0] ?? 'setup'))
                : 'provisioning';
            $failedKey = $activeKey;
        } elseif ($server->status === Server::STATUS_PENDING) {
            $activeKey = 'queued';
        } elseif ($server->status === Server::STATUS_PROVISIONING) {
            $activeKey = filled($server->ip_address) ? 'ssh' : 'provisioning';
        } elseif ($server->status === Server::STATUS_READY && $server->setup_status === Server::SETUP_STATUS_PENDING) {
            $activeKey = 'ssh';
        } elseif ($server->status === Server::STATUS_READY && $server->setup_status === Server::SETUP_STATUS_RUNNING) {
            $activeKey = $lastSeenScriptKey ?? ($scriptStepKeys[0] ?? 'setup');
        } elseif ($server->status === Server::STATUS_READY) {
            $activeKey = 'ready';
        }

        $stepIndex = array_flip(array_column($steps, 'key'));
        $activeIndex = $stepIndex[$activeKey] ?? 0;

        return array_map(function (array $step, int $index) use ($activeIndex, $failedKey, $task, $server): array {
            $state = 'pending';

            if ($failedKey === $step['key']) {
                $state = 'failed';
            } elseif ($index < $activeIndex || ($step['key'] === 'ready' && $activeIndex === $index)) {
                $state = 'completed';
            } elseif ($index === $activeIndex) {
                $state = 'active';
            }

            return [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $state,
                'detail' => $this->stepDetail($step['key'], $task, $server, $state),
                'duration' => $this->stepDuration($step['key'], $task, $server, $state),
            ];
        }, $steps, array_keys($steps));
    }

    protected function stepDetail(string $key, ?Task $task, Server $server, string $state): ?string
    {
        $scriptLabel = $this->scriptStepLabelForKey($task, $key);
        if ($scriptLabel !== null) {
            return match ($state) {
                'active' => $this->scriptStepOutputTail($task, $scriptLabel) ?: __('This setup step is currently running.'),
                'failed' => __('This setup step failed before finishing.'),
                'completed' => __('Completed during server setup.'),
                default => null,
            };
        }

        return match ($key) {
            'queued' => $state === 'active' ? __('Your request has been accepted and is waiting to start provisioning.') : null,
            'provisioning' => $state === 'failed'
                ? __('Provisioning hit an error before the server became reachable.')
                : __('Dply is waiting for the provider to finish building the server.'),
            'ip' => filled($server->ip_address)
                ? __('IP assigned: :ip', ['ip' => $server->ip_address])
                : __('The server will move forward once a public IP is available.'),
            'ssh' => $state === 'active'
                ? __('The server is reachable enough to continue, but SSH setup has not started yet.')
                : __('Dply will continue once SSH is ready.'),
            'setup' => $state === 'failed'
                ? __('The server setup task failed before finishing.')
                : ($task?->tailOutput(3) ?: __('Applying the selected stack and packages.')),
            'ready' => __('The server is ready for normal workspace operations.'),
            default => null,
        };
    }

    protected function stepDuration(string $key, ?Task $task, Server $server, string $state): ?string
    {
        if ($state !== 'active' && $state !== 'completed') {
            return null;
        }

        if (($key === 'setup' || $this->scriptStepLabelForKey($task, $key) !== null) && $task) {
            return $task->getDurationForHumans();
        }

        return $server->created_at?->diffForHumans(now(), true);
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    protected function scriptSteps(?Task $task): array
    {
        if (! $task) {
            return [];
        }

        $source = is_string($task->script_content) && trim($task->script_content) !== ''
            ? $task->script_content
            : (is_string($task->output) ? $task->output : '');

        if (trim($source) === '') {
            return [];
        }

        $labels = $this->extractScriptStepLabels($source);

        return array_map(
            fn (string $label): array => [
                'key' => 'script_'.md5($label),
                'label' => $label,
            ],
            $labels,
        );
    }

    /**
     * @return list<string>
     */
    protected function extractScriptStepLabels(string $content): array
    {
        $labels = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (! str_contains($line, self::SCRIPT_STEP_PREFIX)) {
                continue;
            }

            $label = trim(str_replace(["echo '", 'echo "', "'", '"'], '', strstr($line, self::SCRIPT_STEP_PREFIX) ?: ''));
            $label = preg_replace('/^\[dply-step\]\s*/', '', $label ?? '');
            $label = trim((string) $label);

            if ($label !== '' && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    protected function lastSeenScriptStepKey(?Task $task, array $scriptSteps): ?string
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '' || $scriptSteps === []) {
            return null;
        }

        $seenLabels = $this->extractScriptStepLabels($task->output);
        if ($seenLabels === []) {
            return null;
        }

        $lastSeenLabel = $seenLabels[array_key_last($seenLabels)];

        foreach ($scriptSteps as $step) {
            if ($step['label'] === $lastSeenLabel) {
                return $step['key'];
            }
        }

        return null;
    }

    protected function scriptStepLabelForKey(?Task $task, string $key): ?string
    {
        foreach ($this->scriptSteps($task) as $step) {
            if ($step['key'] === $key) {
                return $step['label'];
            }
        }

        return null;
    }

    protected function scriptStepOutputTail(?Task $task, string $label): ?string
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $task->output) ?: [];
        $filtered = [];
        $capture = false;

        foreach ($lines as $line) {
            if (str_contains($line, self::SCRIPT_STEP_PREFIX.$label)) {
                $capture = true;
                continue;
            }

            if ($capture && str_contains($line, self::SCRIPT_STEP_PREFIX)) {
                break;
            }

            if ($capture && trim($line) !== '') {
                $filtered[] = $line;
            }
        }

        return $filtered === [] ? null : implode("\n", array_slice($filtered, -3));
    }
}
