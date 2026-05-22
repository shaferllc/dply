<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\FunctionInvocation;
use App\Models\Site;
use App\Services\Serverless\FunctionInvoker;
use App\Services\Serverless\FunctionScheduleService;
use App\Services\Serverless\OpenWhiskClient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The OpenWhisk "Platform" workspace tab for a serverless function — a live
 * window onto the DigitalOcean Functions REST API. Three sub-tabs:
 *
 *  - Inspector — the deployed action's real doc (runtime, limits,
 *    annotations, code size, version) and the namespace's actions /
 *    packages / triggers / rules. Plus a delete-action control.
 *  - Triggers & Rules — create / fire / delete triggers, and create /
 *    enable / disable / delete rules that bind a trigger to an action.
 *  - Console — a raw invoke against the function (method / path / body /
 *    headers), recorded as a `source=test` invocation.
 *
 * Everything is read live through {@see OpenWhiskClient}; OpenWhisk is the
 * source of truth, so the UI can never drift from the platform.
 */
class PlatformPanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    /** inspector | triggers | console */
    #[Url(as: 'platform')]
    public string $tab = 'inspector';

    // Schedule (custom-cron) form
    public bool $scheduleFormOpen = false;

    public string $newScheduleCron = '';

    // Trigger form
    public bool $triggerFormOpen = false;

    public string $newTriggerName = '';

    public string $newTriggerParams = '';

    // Rule form
    public bool $ruleFormOpen = false;

    public string $newRuleName = '';

    public string $newRuleTrigger = '';

    public string $newRuleAction = '';

    // Console form
    public string $consoleMethod = 'GET';

    public string $consolePath = '/';

    public string $consoleBody = '';

    public string $consoleHeaders = '';

    /** @var array<string, mixed>|null */
    public ?array $consoleResult = null;

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['inspector', 'triggers', 'console'], true) ? $tab : 'inspector';
    }

    /** Re-renders, which re-queries the OpenWhisk API. */
    public function refresh(): void {}

    // ── Inspector ────────────────────────────────────────────────────────

    public function deleteAction(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $result = $this->client($site)->deleteAction($this->actionName($site));
        $this->toast($result, __('Action deleted — the function will 404 until you redeploy.'));
    }

    // ── Scheduled triggers (DO cron) ─────────────────────────────────────

    public function addSchedulePreset(string $key, FunctionScheduleService $schedules): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $preset = FunctionScheduleService::PRESETS[$key] ?? null;
        if ($preset === null) {
            $this->toastError(__('Unknown schedule preset.'));

            return;
        }

        $this->toast(
            $schedules->add($site, $schedules->presetTriggerName($key), $preset['cron']),
            __(':label — schedule added.', ['label' => $preset['label']]),
        );
    }

    public function addCustomSchedule(FunctionScheduleService $schedules): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $cron = trim($this->newScheduleCron);
        if (preg_match('/^\S+\s+\S+\s+\S+\s+\S+\s+\S+$/', $cron) !== 1) {
            $this->addError('newScheduleCron', __('Enter a 5-field cron expression (UTC).'));

            return;
        }

        if ($this->toast($schedules->add($site, $schedules->customTriggerName($cron), $cron), __('Schedule added.'))) {
            $this->reset('newScheduleCron', 'scheduleFormOpen');
        }
    }

    public function removeSchedule(string $name, FunctionScheduleService $schedules): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->toast($schedules->remove($site, $name), __('Schedule :name removed.', ['name' => $name]));
    }

    // ── Triggers & Rules ─────────────────────────────────────────────────

    public function createTrigger(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->validate([
            'newTriggerName' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'newTriggerParams' => ['nullable', 'string'],
        ]);

        $params = $this->decodeJsonObject($this->newTriggerParams, 'newTriggerParams');
        if ($params === null) {
            return;
        }

        $result = $this->client($site)->putTrigger($this->newTriggerName, $params);
        if ($this->toast($result, __('Trigger created.'))) {
            $this->reset('newTriggerName', 'newTriggerParams', 'triggerFormOpen');
        }
    }

    public function fireTrigger(string $name): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $result = $this->client($site)->fireTrigger($name);
        $this->toast($result, __('Trigger :name fired.', ['name' => $name]));
    }

    public function deleteTrigger(string $name): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->toast($this->client($site)->deleteTrigger($name), __('Trigger :name deleted.', ['name' => $name]));
    }

    public function createRule(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->validate([
            'newRuleName' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'newRuleTrigger' => ['required', 'string'],
            'newRuleAction' => ['required', 'string'],
        ]);

        $result = $this->client($site)->putRule($this->newRuleName, $this->newRuleTrigger, $this->newRuleAction);
        if ($this->toast($result, __('Rule created.'))) {
            $this->reset('newRuleName', 'newRuleTrigger', 'newRuleAction', 'ruleFormOpen');
        }
    }

    public function toggleRule(string $name, string $status): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $next = $status === 'active' ? 'inactive' : 'active';
        $this->toast(
            $this->client($site)->setRuleState($name, $next),
            __('Rule :name :state.', ['name' => $name, 'state' => $next === 'active' ? __('enabled') : __('disabled')]),
        );
    }

    public function deleteRule(string $name): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->toast($this->client($site)->deleteRule($name), __('Rule :name deleted.', ['name' => $name]));
    }

    // ── Console ──────────────────────────────────────────────────────────

    public function sendConsole(FunctionInvoker $invoker): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $method = strtoupper(trim($this->consoleMethod)) ?: 'GET';
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            $method = 'GET';
        }

        $result = $invoker->invoke($site, FunctionInvocation::SOURCE_TEST, null, [
            '__ow_method' => $method,
            '__ow_path' => ltrim(trim($this->consolePath), '/'),
            '__ow_headers' => $this->parseHeaderLines($this->consoleHeaders),
            '__ow_body' => trim($this->consoleBody),
            '__ow_query' => '',
        ]);

        $invocation = $result['invocation'];
        $this->consoleResult = [
            'ok' => $result['ok'],
            'error' => $result['error'],
            'success' => $invocation?->success ?? false,
            'status' => $invocation?->status_code,
            'duration' => $invocation?->duration_ms ?? 0,
            'logs' => $invocation?->logLines() ?? [],
            'excerpt' => $invocation?->result_excerpt,
        ];
    }

    public function render(): View
    {
        $site = $this->site();
        $client = $this->client($site);
        $actionName = $this->actionName($site);

        $data = ['site' => $site, 'actionName' => $actionName];

        if ($this->tab === 'inspector') {
            $data['action'] = $client->action($actionName);
            $data['actions'] = $client->actions();
            $data['packages'] = $client->packages();
            $data['triggers'] = $client->triggers();
            $data['rules'] = $client->rules();
        } elseif ($this->tab === 'triggers') {
            $data['scheduled'] = app(FunctionScheduleService::class)->list($site);
            $data['schedulePresets'] = FunctionScheduleService::PRESETS;
            $data['triggers'] = $client->triggers();
            $data['rules'] = $client->rules();
            $data['actions'] = $client->actions();
        }

        return view('livewire.serverless.platform-panel', $data);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function site(): Site
    {
        return Site::with('server')->findOrFail($this->siteId);
    }

    private function client(Site $site): OpenWhiskClient
    {
        return new OpenWhiskClient($site->server);
    }

    private function actionName(Site $site): string
    {
        $cfg = $site->serverlessConfig();
        $name = trim((string) ($cfg['action_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $url = trim((string) ($cfg['action_url'] ?? ''));

        return $url === '' ? '' : basename(rtrim($url, '/'));
    }

    /**
     * Decode a JSON object from a form field, or add a validation error and
     * return null. An empty string yields an empty array (no params).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $raw, string $field): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->addError($field, __('Enter a valid JSON object.'));

            return null;
        }

        return $decoded;
    }

    /**
     * Parse `Key: Value` lines into a header map for a console invoke.
     *
     * @return array<string, string>
     */
    private function parseHeaderLines(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $headers[$key] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Toast an OpenWhisk result; returns whether it succeeded.
     *
     * @param  array{ok: bool, error: ?string, data: mixed}  $result
     */
    private function toast(array $result, string $successMessage): bool
    {
        if ($result['ok']) {
            $this->toastSuccess($successMessage);

            return true;
        }

        $this->toastError($result['error'] ?? __('OpenWhisk request failed.'));

        return false;
    }
}
