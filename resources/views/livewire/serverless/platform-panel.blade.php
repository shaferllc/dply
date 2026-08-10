@php
    $tabs = [
        'inspector' => __('Inspector'),
        'triggers' => __('Triggers'),
        'console' => __('Console'),
        'credentials' => __('Credentials'),
    ];
    $tabIcons = [
        'inspector' => 'heroicon-o-cube',
        'triggers' => 'heroicon-o-clock',
        'console' => 'heroicon-o-command-line',
        'credentials' => 'heroicon-o-key',
    ];
    $tabNotes = [
        'inspector' => __('Deployed action and namespace inventory — live from OpenWhisk.'),
        'triggers' => __('Schedules, triggers, and rules for this function.'),
        'console' => __('Send a test request. Results also appear on Logs.'),
        'credentials' => __('The namespace access key dply uses to reach this function.'),
    ];

    /** Pull a list payload out of an OpenWhisk {ok,error,data} result. */
    $listOf = function (?array $result): array {
        return ($result && $result['ok'] && is_array($result['data'])) ? $result['data'] : [];
    };

    $panelPad = 'px-3 py-2.5 sm:px-4';
    $stripHead = 'border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4';
@endphp
<section class="dply-card min-w-0 overflow-hidden p-0">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-cube"
        :title="__('Platform')"
        :note="$tabNotes[$tab] ?? $tabNotes['inspector']"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="refresh"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
            >
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="refresh" aria-hidden="true" />
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="refresh" aria-hidden="true" />
                {{ __('Refresh') }}
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <x-server-workspace-tablist :aria-label="__('Platform sections')" scroll bare class="!mb-0 w-full">
            @foreach ($tabs as $key => $label)
                <x-server-workspace-tab
                    id="platform-tab-{{ $key }}"
                    :active="$tab === $key"
                    :icon="$tabIcons[$key]"
                    wire:click="setTab('{{ $key }}')"
                >{{ $label }}</x-server-workspace-tab>
            @endforeach
        </x-server-workspace-tablist>
    </div>

    {{-- ── Inspector ───────────────────────────────────────────────────── --}}
    @if ($tab === 'inspector')
        @php
            $actionDoc = ($action['ok'] && is_array($action['data'])) ? $action['data'] : null;
            $annotations = [];
            foreach ((array) ($actionDoc['annotations'] ?? []) as $a) {
                if (is_array($a) && isset($a['key'])) {
                    $annotations[$a['key']] = $a['value'];
                }
            }
            $codeBytes = (int) round(strlen((string) data_get($actionDoc, 'exec.code', '')) * 0.75);
            $counts = [
                ['Actions', count($listOf($actions))],
                ['Packages', count($listOf($packages))],
                ['Triggers', count($listOf($triggers))],
                ['Rules', count($listOf($rules))],
            ];
        @endphp

        @if (! $action['ok'])
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-3 py-2.5 text-xs text-amber-900 sm:px-4">
                {{ $action['error'] ?? __('Could not read the action from OpenWhisk.') }}
            </div>
        @elseif ($actionDoc)
            <div class="border-b border-brand-ink/10">
                <div class="{{ $stripHead }} flex flex-wrap items-center justify-between gap-2">
                    <h3 class="flex min-w-0 items-center gap-1.5 text-xs font-semibold text-brand-ink">
                        <span>{{ __('Action') }}</span>
                        <code class="truncate font-mono text-xs text-brand-moss">{{ $actionName }}</code>
                    </h3>
                    <span class="shrink-0 font-mono text-xs text-brand-moss">v{{ $actionDoc['version'] ?? '—' }}</span>
                </div>
                <dl class="{{ $panelPad }} grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ([
                        ['Runtime', data_get($actionDoc, 'exec.kind', '—')],
                        ['Entry', data_get($actionDoc, 'exec.main', 'main')],
                        ['Binary', data_get($actionDoc, 'exec.binary') ? 'yes' : 'no'],
                        ['Memory', (data_get($actionDoc, 'limits.memory', 0)).' MB'],
                        ['Timeout', (data_get($actionDoc, 'limits.timeout', 0)).' ms'],
                        ['Concurrency', data_get($actionDoc, 'limits.concurrency', 1)],
                        ['Log limit', (data_get($actionDoc, 'limits.logs', 0)).' MB'],
                        ['Web export', ($annotations['web-export'] ?? false) ? 'true' : 'false'],
                        ['Code size', $codeBytes > 0 ? number_format($codeBytes / 1024, 0).' KB' : '—'],
                        ['Published', ($actionDoc['publish'] ?? false) ? 'true' : 'false'],
                    ] as [$label, $value])
                        <div class="min-w-0">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-moss/70">{{ __($label) }}</dt>
                            <dd class="mt-0.5 truncate font-mono text-xs text-brand-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @else
            <div class="border-b border-brand-ink/10 px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
                {{ __('No action deployed in this namespace yet.') }}
            </div>
        @endif

        <div class="border-b border-brand-ink/10">
            <div class="{{ $stripHead }}">
                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Namespace') }}</h3>
            </div>
            <dl class="{{ $panelPad }} grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($counts as [$label, $n])
                    <div class="flex items-baseline justify-between gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1.5">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-moss/70">{{ __($label) }}</dt>
                        <dd class="text-sm font-semibold tabular-nums text-brand-ink">{{ $n }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        @if ($actionDoc)
            <div class="flex flex-wrap items-center justify-between gap-2 bg-rose-50/70 px-3 py-2.5 sm:px-4">
                <p class="min-w-0 text-xs text-rose-900">{{ __('Delete this action. The function 404s until you redeploy.') }}</p>
                <button
                    type="button"
                    wire:click="deleteAction"
                    wire:confirm="{{ __('Delete the action :name from OpenWhisk?', ['name' => $actionName]) }}"
                    class="inline-flex shrink-0 items-center rounded-lg bg-rose-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-rose-700"
                >
                    {{ __('Delete action') }}
                </button>
            </div>
        @endif

    {{-- ── Triggers & Rules ────────────────────────────────────────────── --}}
    @elseif ($tab === 'triggers')
        @php
            $triggerList = $listOf($triggers);
            $ruleList = $listOf($rules);
            $actionList = $listOf($actions);

            $scheduledList = $scheduled['ok'] ? ($scheduled['triggers'] ?? []) : [];
            $scheduledByName = collect($scheduledList)->keyBy('name');
            $presetNames = collect(array_keys($schedulePresets))->map(fn ($k) => 'dply-'.$k)->all();
            $customScheduled = collect($scheduledList)->reject(fn ($t) => in_array($t['name'] ?? '', $presetNames, true))->values();
        @endphp

        <div class="border-b border-brand-ink/10">
            <div class="{{ $stripHead }}">
                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Schedules') }}</h3>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('DigitalOcean cron (UTC). One click adds a trigger.') }}</p>
            </div>

            @if (! $scheduled['ok'])
                <div class="{{ $panelPad }} text-xs text-amber-900 bg-amber-50/60">{{ $scheduled['error'] }}</div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($schedulePresets as $key => $preset)
                        @php $added = $scheduledByName->has('dply-'.$key); @endphp
                        <li class="flex flex-wrap items-center gap-2 {{ $panelPad }} text-xs">
                            <span class="font-semibold text-brand-ink">{{ $preset['label'] }}</span>
                            <span class="font-mono text-xs text-brand-moss/60">{{ $preset['cron'] }}</span>
                            <span class="ml-auto flex items-center gap-1.5">
                                @if ($added)
                                    <span class="text-xs font-semibold text-brand-forest">{{ __('Added') }}</span>
                                    <button type="button" wire:click="removeSchedule('dply-{{ $key }}')"
                                            class="rounded px-1.5 py-0.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                                @else
                                    <button type="button" wire:click="addSchedulePreset('{{ $key }}')"
                                            class="rounded border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Add') }}</button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="{{ $panelPad }} border-t border-brand-ink/10">
                    <button type="button" wire:click="$toggle('scheduleFormOpen')"
                            class="text-xs font-semibold text-brand-sage hover:underline">
                        {{ $scheduleFormOpen ? __('Cancel') : __('Custom cron…') }}
                    </button>
                    @if ($scheduleFormOpen)
                        <form wire:submit="addCustomSchedule" class="mt-2 flex flex-wrap items-start gap-2">
                            <div>
                                <input type="text" wire:model="newScheduleCron" placeholder="0 9 * * 1-5"
                                       class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs">
                                <x-input-error :messages="$errors->get('newScheduleCron')" class="mt-1" />
                            </div>
                            <button type="submit" class="rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-forest/90">{{ __('Add') }}</button>
                        </form>
                    @endif
                </div>

                @if ($customScheduled->isNotEmpty())
                    <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                        @foreach ($customScheduled as $trigger)
                            @php $tname = (string) ($trigger['name'] ?? ''); @endphp
                            <li class="flex flex-wrap items-center gap-2 {{ $panelPad }} text-xs">
                                <span class="font-mono text-brand-ink">{{ $tname }}</span>
                                <span class="font-mono text-xs text-brand-moss/60">{{ data_get($trigger, 'scheduled_details.cron', '—') }}</span>
                                @if (! ($trigger['is_enabled'] ?? true))
                                    <span class="rounded bg-brand-sand px-1.5 py-0.5 text-2xs font-semibold text-brand-moss">{{ __('disabled') }}</span>
                                @endif
                                @if ($next = data_get($trigger, 'scheduled_runs.next_run_at'))
                                    <span class="text-xs text-brand-moss/50">{{ __('next') }} {{ \Illuminate\Support\Carbon::parse($next)->diffForHumans() }}</span>
                                @endif
                                <button type="button" wire:click="removeSchedule('{{ $tname }}')"
                                        class="ml-auto rounded px-1.5 py-0.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>

        <div class="border-b border-brand-ink/10">
            <div class="{{ $stripHead }} flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Triggers') }}</h3>
                <button type="button" wire:click="$toggle('triggerFormOpen')"
                        class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                    {{ $triggerFormOpen ? __('Cancel') : __('New') }}
                </button>
            </div>

            @if ($triggerFormOpen)
                <form wire:submit="createTrigger" class="space-y-2 border-b border-brand-ink/10 bg-brand-sand/10 {{ $panelPad }}">
                    <input type="text" wire:model="newTriggerName" placeholder="{{ __('trigger-name') }}"
                           class="w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs">
                    <x-input-error :messages="$errors->get('newTriggerName')" />
                    <textarea wire:model="newTriggerParams" rows="2" placeholder='{{ __('Default params JSON, e.g. {"region":"nyc"}') }}'
                              class="w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs"></textarea>
                    <x-input-error :messages="$errors->get('newTriggerParams')" />
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-forest/90">{{ __('Create') }}</button>
                </form>
            @endif

            @if (! $triggers['ok'])
                <div class="{{ $panelPad }} text-xs text-amber-900 bg-amber-50/60">{{ $triggers['error'] }}</div>
            @elseif ($triggerList === [])
                <p class="{{ $panelPad }} text-xs text-brand-moss/60">{{ __('No triggers in this namespace.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($triggerList as $trigger)
                        @php $tname = (string) ($trigger['name'] ?? ''); @endphp
                        <li class="flex flex-wrap items-center gap-2 {{ $panelPad }} text-xs">
                            <span class="font-mono text-brand-ink">{{ $tname }}</span>
                            <span class="text-xs text-brand-moss/60">{{ trans_choice('{0}no params|{1}1 param|[2,*]:count params', count((array) ($trigger['parameters'] ?? [])), ['count' => count((array) ($trigger['parameters'] ?? []))]) }}</span>
                            <span class="ml-auto flex gap-1">
                                <button type="button" wire:click="fireTrigger('{{ $tname }}')"
                                        class="rounded border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Fire') }}</button>
                                <button type="button" wire:click="deleteTrigger('{{ $tname }}')" wire:confirm="{{ __('Delete trigger :n?', ['n' => $tname]) }}"
                                        class="rounded px-1.5 py-0.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <div class="{{ $stripHead }} flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Rules') }}</h3>
                <button type="button" wire:click="$toggle('ruleFormOpen')"
                        class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                    {{ $ruleFormOpen ? __('Cancel') : __('New') }}
                </button>
            </div>

            @if ($ruleFormOpen)
                <form wire:submit="createRule" class="space-y-2 border-b border-brand-ink/10 bg-brand-sand/10 {{ $panelPad }}">
                    <input type="text" wire:model="newRuleName" placeholder="{{ __('rule-name') }}"
                           class="w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs">
                    <x-input-error :messages="$errors->get('newRuleName')" />
                    <div class="flex flex-wrap gap-2">
                        <select wire:model="newRuleTrigger" class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs">
                            <option value="">{{ __('Trigger…') }}</option>
                            @foreach ($triggerList as $trigger)
                                <option value="{{ $trigger['name'] ?? '' }}">{{ $trigger['name'] ?? '' }}</option>
                            @endforeach
                        </select>
                        <select wire:model="newRuleAction" class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs">
                            <option value="">{{ __('Action…') }}</option>
                            @foreach ($actionList as $a)
                                <option value="{{ $a['name'] ?? '' }}">{{ $a['name'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-input-error :messages="$errors->get('newRuleTrigger')" />
                    <x-input-error :messages="$errors->get('newRuleAction')" />
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-forest/90">{{ __('Create') }}</button>
                </form>
            @endif

            @if (! $rules['ok'])
                <div class="{{ $panelPad }} text-xs text-amber-900 bg-amber-50/60">{{ $rules['error'] }}</div>
            @elseif ($ruleList === [])
                <p class="{{ $panelPad }} text-xs text-brand-moss/60">{{ __('No rules in this namespace.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($ruleList as $rule)
                        @php
                            $rname = (string) ($rule['name'] ?? '');
                            $rstatus = (string) ($rule['status'] ?? 'inactive');
                            $rtrigger = is_array($rule['trigger'] ?? null) ? ($rule['trigger']['name'] ?? '') : (string) ($rule['trigger'] ?? '');
                            $raction = is_array($rule['action'] ?? null) ? ($rule['action']['name'] ?? '') : (string) ($rule['action'] ?? '');
                        @endphp
                        <li class="flex flex-wrap items-center gap-2 {{ $panelPad }} text-xs">
                            <span @class([
                                'inline-flex items-center rounded px-1.5 py-0.5 text-2xs font-semibold',
                                'bg-brand-forest/15 text-brand-forest' => $rstatus === 'active',
                                'bg-brand-sand text-brand-moss' => $rstatus !== 'active',
                            ])>{{ $rstatus }}</span>
                            <span class="font-mono text-brand-ink">{{ $rname }}</span>
                            <span class="font-mono text-xs text-brand-moss/60">{{ $rtrigger }} → {{ $raction }}</span>
                            <span class="ml-auto flex gap-1">
                                <button type="button" wire:click="toggleRule('{{ $rname }}', '{{ $rstatus }}')"
                                        class="rounded border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ $rstatus === 'active' ? __('Disable') : __('Enable') }}</button>
                                <button type="button" wire:click="deleteRule('{{ $rname }}')" wire:confirm="{{ __('Delete rule :n?', ['n' => $rname]) }}"
                                        class="rounded px-1.5 py-0.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    {{-- ── Console ─────────────────────────────────────────────────────── --}}
    @elseif ($tab === 'console')
        <div class="border-b border-brand-ink/10">
            <div class="{{ $stripHead }}">
                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Request') }}</h3>
            </div>
            <div class="{{ $panelPad }} space-y-2.5">
                <div class="flex flex-wrap items-end gap-2">
                    <label class="text-xs text-brand-moss">
                        <span class="block text-xs font-semibold">{{ __('Method') }}</span>
                        <select wire:model="consoleMethod" class="mt-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs">
                            @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $m)
                                <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="min-w-0 flex-1 text-xs text-brand-moss">
                        <span class="block text-xs font-semibold">{{ __('Path') }}</span>
                        <input type="text" wire:model="consolePath" placeholder="/"
                               class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs">
                    </label>
                </div>
                <label class="block text-xs text-brand-moss">
                    <span class="text-xs font-semibold">{{ __('Body') }}</span>
                    <textarea wire:model="consoleBody" rows="2" placeholder='{{ __('JSON or raw') }}'
                              class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs"></textarea>
                </label>
                <label class="block text-xs text-brand-moss">
                    <span class="text-xs font-semibold">{{ __('Headers') }}</span>
                    <textarea wire:model="consoleHeaders" rows="2" placeholder="{{ __('One per line — Header: value') }}"
                              class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs"></textarea>
                </label>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="sendConsole" wire:loading.attr="disabled" wire:target="sendConsole"
                            class="inline-flex items-center rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-forest/90 disabled:opacity-60">
                        <span wire:loading.remove wire:target="sendConsole">{{ __('Send') }}</span>
                        <span wire:loading wire:target="sendConsole">{{ __('Invoking…') }}</span>
                    </button>
                    <p class="text-xs text-brand-moss/60">{{ __('Recorded as a test invocation.') }}</p>
                </div>
            </div>
        </div>

        @if ($consoleResult !== null)
            <div class="{{ $panelPad }}">
                @if (! $consoleResult['ok'])
                    <p class="text-xs text-rose-700">{{ $consoleResult['error'] ?? __('Request failed.') }}</p>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'inline-flex items-center rounded px-1.5 py-0.5 text-2xs font-semibold',
                            'bg-brand-forest/15 text-brand-forest' => $consoleResult['success'],
                            'bg-rose-100 text-rose-700' => ! $consoleResult['success'],
                        ])>{{ $consoleResult['success'] ? __('OK') : __('Error') }}</span>
                        @if ($consoleResult['status'])
                            <span class="font-mono text-xs text-brand-moss">HTTP {{ $consoleResult['status'] }}</span>
                        @endif
                        <span class="text-xs text-brand-moss">{{ $consoleResult['duration'] }}ms</span>
                    </div>
                    @if (trim((string) $consoleResult['excerpt']) !== '')
                        <pre class="mt-2 max-h-40 overflow-auto rounded-lg border border-brand-ink/10 bg-brand-sand/30 p-2.5 text-xs leading-relaxed text-brand-ink">{{ $consoleResult['excerpt'] }}</pre>
                    @endif
                    @if (count($consoleResult['logs']) > 0)
                        <pre class="mt-2 max-h-40 overflow-auto rounded-lg bg-brand-ink p-2.5 text-xs leading-relaxed text-brand-cream">{{ implode("\n", $consoleResult['logs']) }}</pre>
                    @endif
                @endif
            </div>
        @endif

    {{-- ── Credentials ─────────────────────────────────────────────────── --}}
    @elseif ($tab === 'credentials')
        <div class="border-b border-brand-ink/10">
            <div class="{{ $stripHead }}">
                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Namespace access') }}</h3>
            </div>
            <div class="{{ $panelPad }} space-y-3">
                <dl class="grid grid-cols-1 overflow-hidden rounded-lg border border-brand-ink/10 sm:grid-cols-3">
                    @foreach ([
                        __('Namespace') => $namespace ?: '—',
                        __('API host') => $apiHost ?: '—',
                        __('Access key ID') => $accessKeyId ?: '—',
                    ] as $label => $value)
                        <div class="min-w-0 border-b border-r border-brand-ink/10 bg-brand-sand/20 px-3 py-2 last:border-b-0">
                            <dt class="text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $label }}</dt>
                            <dd class="mt-0.5 truncate font-mono text-xs text-brand-ink" title="{{ $value }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-2xs text-brand-moss">
                        {{ __('The secret half of the key is stored and never shown. Checking asks the namespace whether the key still works.') }}
                    </p>
                    <button type="button" wire:click="verifyCredentials" wire:loading.attr="disabled" wire:target="verifyCredentials"
                        class="dply-btn dply-btn-xs dply-btn-outline shrink-0">
                        <span wire:loading.remove wire:target="verifyCredentials">{{ __('Check credentials') }}</span>
                        <span wire:loading wire:target="verifyCredentials">{{ __('Checking…') }}</span>
                    </button>
                </div>

                @if ($credentialCheck !== null)
                    <div @class([
                        'rounded-lg border px-3 py-2 text-xs',
                        'border-brand-forest/30 bg-brand-forest/10 text-brand-forest' => $credentialCheck['ok'],
                        'border-rose-200 bg-rose-50 text-rose-700' => ! $credentialCheck['ok'],
                    ])>
                        @if ($credentialCheck['ok'])
                            {{ __('The key works — the namespace reports :count action(s).', ['count' => $credentialCheck['actions']]) }}
                        @else
                            {{ $credentialCheck['error'] }}
                        @endif
                    </div>
                @endif

                {{-- DigitalOcean exposes no REST API for minting or revoking
                     namespace keys, so these are the real path — dply cannot
                     do it on the operator's behalf and shouldn't pretend to. --}}
                <div class="border-t border-brand-ink/10 pt-3">
                    <p class="mb-2 text-2xs text-brand-moss">
                        {{ __('Keys are created and revoked with doctl or in the DigitalOcean control panel. After rotating, update the host credentials in dply.') }}
                    </p>
                    <x-cli-snippet :commands="[
                        ['label' => __('List keys'), 'command' => 'doctl serverless key list'.($namespace ? ' --namespace '.$namespace : '')],
                        ['label' => __('Create a key'), 'command' => 'doctl serverless key create --name dply'.($namespace ? ' --namespace '.$namespace : '')],
                        ['label' => __('Revoke a key'), 'command' => 'doctl serverless key delete <key-id>'.($namespace ? ' --namespace '.$namespace : '')],
                    ]" />
                </div>
            </div>
        </div>
    @endif
</section>
