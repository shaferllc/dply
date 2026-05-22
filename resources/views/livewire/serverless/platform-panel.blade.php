@php
    $tabs = [
        'inspector' => __('Inspector'),
        'triggers' => __('Triggers & Rules'),
        'console' => __('Console'),
    ];
    $tabIcons = [
        'inspector' => 'heroicon-o-cube',
        'triggers' => 'heroicon-o-clock',
        'console' => 'heroicon-o-command-line',
    ];

    /** Pull a list payload out of an OpenWhisk {ok,error,data} result. */
    $listOf = function (?array $result): array {
        return ($result && $result['ok'] && is_array($result['data'])) ? $result['data'] : [];
    };
@endphp
<div class="dply-card p-6 sm:p-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Platform') }}</p>
            <h2 class="mt-1 text-lg font-bold text-brand-ink">{{ $tabs[$tab] }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                @switch($tab)
                    @case('triggers')
                        {{ __('OpenWhisk triggers and the rules that bind them to actions — live from the namespace.') }}
                        @break
                    @case('console')
                        {{ __('Invoke the function with a request you compose — method, path, body, headers.') }}
                        @break
                    @default
                        {{ __('The live OpenWhisk view of this function — the deployed action and the namespace around it.') }}
                @endswitch
            </p>
        </div>
        <button type="button" wire:click="refresh" wire:loading.attr="disabled"
                class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
            {{ __('Refresh') }}
        </button>
    </div>

    <x-server-workspace-tablist :aria-label="__('Platform sections')" class="!mb-0">
        @foreach ($tabs as $key => $label)
            <x-server-workspace-tab
                id="platform-tab-{{ $key }}"
                :active="$tab === $key"
                :icon="$tabIcons[$key]"
                wire:click="setTab('{{ $key }}')"
            >{{ $label }}</x-server-workspace-tab>
        @endforeach
    </x-server-workspace-tablist>

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
        @endphp

        @if (! $action['ok'])
            <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">
                {{ $action['error'] ?? __('The action could not be read from OpenWhisk.') }}
            </div>
        @elseif ($actionDoc)
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-bold text-brand-ink">{{ __('Action') }} <span class="font-mono">{{ $actionName }}</span></h3>
                    <span class="font-mono text-[11px] text-brand-moss">v{{ $actionDoc['version'] ?? '—' }}</span>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                    @foreach ([
                        ['Runtime', data_get($actionDoc, 'exec.kind', '—')],
                        ['Entry function', data_get($actionDoc, 'exec.main', 'main')],
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
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss/70">{{ __($label) }}</dt>
                            <dd class="mt-0.5 truncate font-mono text-sm text-brand-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @else
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                {{ __('No action is deployed in this namespace yet.') }}
            </div>
        @endif

        {{-- Namespace summary --}}
        @php
            $counts = [
                ['Actions', count($listOf($actions))],
                ['Packages', count($listOf($packages))],
                ['Triggers', count($listOf($triggers))],
                ['Rules', count($listOf($rules))],
            ];
        @endphp
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Namespace') }}</p>
            <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($counts as [$label, $n])
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __($label) }}</dt>
                        <dd class="mt-0.5 text-lg font-bold text-brand-ink">{{ $n }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        @if ($actionDoc)
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-rose-900">{{ __('Delete this action from OpenWhisk. The function 404s until you redeploy.') }}</p>
                    <button type="button" wire:click="deleteAction"
                            wire:confirm="{{ __('Delete the action :name from OpenWhisk?', ['name' => $actionName]) }}"
                            class="inline-flex items-center rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">
                        {{ __('Delete action') }}
                    </button>
                </div>
            </div>
        @endif

    {{-- ── Triggers & Rules ────────────────────────────────────────────── --}}
    @elseif ($tab === 'triggers')
        @php
            $triggerList = $listOf($triggers);
            $ruleList = $listOf($rules);
            $actionList = $listOf($actions);

            // Scheduled triggers (DO cron) — index by name to match presets.
            $scheduledList = $scheduled['ok'] ? ($scheduled['triggers'] ?? []) : [];
            $scheduledByName = collect($scheduledList)->keyBy('name');
            $presetNames = collect(array_keys($schedulePresets))->map(fn ($k) => 'dply-'.$k)->all();
            $customScheduled = collect($scheduledList)->reject(fn ($t) => in_array($t['name'] ?? '', $presetNames, true))->values();
        @endphp

        {{-- Scheduled triggers — DO fires the function on a cron (UTC). --}}
        <div class="space-y-3">
            <div>
                <h3 class="text-sm font-bold text-brand-ink">{{ __('Scheduled triggers') }}</h3>
                <p class="text-xs text-brand-moss">{{ __('DigitalOcean invokes this function on a schedule — all times UTC. One click adds the trigger.') }}</p>
            </div>

            @if (! $scheduled['ok'])
                <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">{{ $scheduled['error'] }}</div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($schedulePresets as $key => $preset)
                        @php $added = $scheduledByName->has('dply-'.$key); @endphp
                        <li class="flex flex-wrap items-center gap-2 py-2 text-xs">
                            <span class="font-semibold text-brand-ink">{{ $preset['label'] }}</span>
                            <span class="font-mono text-brand-moss/60">{{ $preset['cron'] }}</span>
                            <span class="ml-auto">
                                @if ($added)
                                    <span class="mr-1 font-semibold text-brand-forest">{{ __('Added') }}</span>
                                    <button type="button" wire:click="removeSchedule('dply-{{ $key }}')"
                                            class="rounded px-2 py-1 font-semibold text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                                @else
                                    <button type="button" wire:click="addSchedulePreset('{{ $key }}')"
                                            class="rounded border border-brand-ink/15 bg-white px-2 py-1 font-semibold text-brand-ink hover:border-brand-sage/40">{{ __('Add') }}</button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                {{-- Custom cron --}}
                <div>
                    <button type="button" wire:click="$toggle('scheduleFormOpen')"
                            class="text-xs font-semibold text-brand-sage hover:underline">
                        {{ $scheduleFormOpen ? __('Cancel') : __('Custom cron…') }}
                    </button>
                    @if ($scheduleFormOpen)
                        <form wire:submit="addCustomSchedule" class="mt-2 flex flex-wrap items-start gap-2">
                            <div>
                                <input type="text" wire:model="newScheduleCron" placeholder="0 9 * * 1-5"
                                       class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs">
                                <x-input-error :messages="$errors->get('newScheduleCron')" class="mt-1" />
                            </div>
                            <button type="submit" class="rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest/90">{{ __('Add schedule') }}</button>
                        </form>
                    @endif
                </div>

                @if ($customScheduled->isNotEmpty())
                    <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10 pt-1">
                        @foreach ($customScheduled as $trigger)
                            @php $tname = (string) ($trigger['name'] ?? ''); @endphp
                            <li class="flex flex-wrap items-center gap-2 py-2 text-xs">
                                <span class="font-mono text-brand-ink">{{ $tname }}</span>
                                <span class="font-mono text-brand-moss/60">{{ data_get($trigger, 'scheduled_details.cron', '—') }}</span>
                                @if (! ($trigger['is_enabled'] ?? true))
                                    <span class="rounded bg-brand-sand px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss">{{ __('disabled') }}</span>
                                @endif
                                @if ($next = data_get($trigger, 'scheduled_runs.next_run_at'))
                                    <span class="text-brand-moss/50">{{ __('next') }} {{ \Illuminate\Support\Carbon::parse($next)->diffForHumans() }}</span>
                                @endif
                                <button type="button" wire:click="removeSchedule('{{ $tname }}')"
                                        class="ml-auto rounded px-2 py-1 font-semibold text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>

        {{-- Triggers --}}
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-brand-ink">{{ __('Triggers') }}</h3>
                <button type="button" wire:click="$toggle('triggerFormOpen')"
                        class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                    {{ $triggerFormOpen ? __('Cancel') : __('New trigger') }}
                </button>
            </div>

            @if ($triggerFormOpen)
                <form wire:submit="createTrigger" class="space-y-2 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3">
                    <input type="text" wire:model="newTriggerName" placeholder="{{ __('trigger-name') }}"
                           class="w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs">
                    <x-input-error :messages="$errors->get('newTriggerName')" />
                    <textarea wire:model="newTriggerParams" rows="2" placeholder='{{ __('Default parameters as JSON, e.g. {"region":"nyc"}') }}'
                              class="w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs"></textarea>
                    <x-input-error :messages="$errors->get('newTriggerParams')" />
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest/90">{{ __('Create trigger') }}</button>
                </form>
            @endif

            @if (! $triggers['ok'])
                <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">{{ $triggers['error'] }}</div>
            @elseif ($triggerList === [])
                <p class="text-xs text-brand-moss/60">{{ __('No triggers in this namespace.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($triggerList as $trigger)
                        @php $tname = (string) ($trigger['name'] ?? ''); @endphp
                        <li class="flex flex-wrap items-center gap-2 py-2 text-xs">
                            <span class="font-mono text-brand-ink">{{ $tname }}</span>
                            <span class="text-brand-moss/60">{{ trans_choice('{0}no params|{1}1 param|[2,*]:count params', count((array) ($trigger['parameters'] ?? [])), ['count' => count((array) ($trigger['parameters'] ?? []))]) }}</span>
                            <span class="ml-auto flex gap-1.5">
                                <button type="button" wire:click="fireTrigger('{{ $tname }}')"
                                        class="rounded border border-brand-ink/15 bg-white px-2 py-1 font-semibold text-brand-ink hover:border-brand-sage/40">{{ __('Fire') }}</button>
                                <button type="button" wire:click="deleteTrigger('{{ $tname }}')" wire:confirm="{{ __('Delete trigger :n?', ['n' => $tname]) }}"
                                        class="rounded px-2 py-1 font-semibold text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Rules --}}
        <div class="space-y-3 border-t border-brand-ink/10 pt-5">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-brand-ink">{{ __('Rules') }}</h3>
                <button type="button" wire:click="$toggle('ruleFormOpen')"
                        class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                    {{ $ruleFormOpen ? __('Cancel') : __('New rule') }}
                </button>
            </div>

            @if ($ruleFormOpen)
                <form wire:submit="createRule" class="space-y-2 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3">
                    <input type="text" wire:model="newRuleName" placeholder="{{ __('rule-name') }}"
                           class="w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs">
                    <x-input-error :messages="$errors->get('newRuleName')" />
                    <div class="flex flex-wrap gap-2">
                        <select wire:model="newRuleTrigger" class="flex-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                            <option value="">{{ __('Trigger…') }}</option>
                            @foreach ($triggerList as $trigger)
                                <option value="{{ $trigger['name'] ?? '' }}">{{ $trigger['name'] ?? '' }}</option>
                            @endforeach
                        </select>
                        <select wire:model="newRuleAction" class="flex-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                            <option value="">{{ __('Action…') }}</option>
                            @foreach ($actionList as $a)
                                <option value="{{ $a['name'] ?? '' }}">{{ $a['name'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-input-error :messages="$errors->get('newRuleTrigger')" />
                    <x-input-error :messages="$errors->get('newRuleAction')" />
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest/90">{{ __('Create rule') }}</button>
                </form>
            @endif

            @if (! $rules['ok'])
                <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">{{ $rules['error'] }}</div>
            @elseif ($ruleList === [])
                <p class="text-xs text-brand-moss/60">{{ __('No rules in this namespace.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($ruleList as $rule)
                        @php
                            $rname = (string) ($rule['name'] ?? '');
                            $rstatus = (string) ($rule['status'] ?? 'inactive');
                            $rtrigger = is_array($rule['trigger'] ?? null) ? ($rule['trigger']['name'] ?? '') : (string) ($rule['trigger'] ?? '');
                            $raction = is_array($rule['action'] ?? null) ? ($rule['action']['name'] ?? '') : (string) ($rule['action'] ?? '');
                        @endphp
                        <li class="flex flex-wrap items-center gap-2 py-2 text-xs">
                            <span @class([
                                'inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold',
                                'bg-brand-forest/15 text-brand-forest' => $rstatus === 'active',
                                'bg-brand-sand text-brand-moss' => $rstatus !== 'active',
                            ])>{{ $rstatus }}</span>
                            <span class="font-mono text-brand-ink">{{ $rname }}</span>
                            <span class="font-mono text-brand-moss/60">{{ $rtrigger }} → {{ $raction }}</span>
                            <span class="ml-auto flex gap-1.5">
                                <button type="button" wire:click="toggleRule('{{ $rname }}', '{{ $rstatus }}')"
                                        class="rounded border border-brand-ink/15 bg-white px-2 py-1 font-semibold text-brand-ink hover:border-brand-sage/40">{{ $rstatus === 'active' ? __('Disable') : __('Enable') }}</button>
                                <button type="button" wire:click="deleteRule('{{ $rname }}')" wire:confirm="{{ __('Delete rule :n?', ['n' => $rname]) }}"
                                        class="rounded px-2 py-1 font-semibold text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    {{-- ── Console ─────────────────────────────────────────────────────── --}}
    @elseif ($tab === 'console')
        <div class="space-y-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
            <div class="flex flex-wrap items-end gap-2">
                <label class="text-xs text-brand-moss">
                    <span class="block font-semibold">{{ __('Method') }}</span>
                    <select wire:model="consoleMethod" class="mt-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                        @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $m)
                            <option value="{{ $m }}">{{ $m }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex-1 text-xs text-brand-moss">
                    <span class="block font-semibold">{{ __('Path') }}</span>
                    <input type="text" wire:model="consolePath" placeholder="/"
                           class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs">
                </label>
            </div>
            <label class="block text-xs text-brand-moss">
                <span class="font-semibold">{{ __('Body') }}</span>
                <textarea wire:model="consoleBody" rows="3" placeholder='{{ __('Request body (JSON or raw)') }}'
                          class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs"></textarea>
            </label>
            <label class="block text-xs text-brand-moss">
                <span class="font-semibold">{{ __('Headers') }}</span>
                <textarea wire:model="consoleHeaders" rows="2" placeholder="{{ __('One per line — Header: value') }}"
                          class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs"></textarea>
            </label>
            <button type="button" wire:click="sendConsole" wire:loading.attr="disabled" wire:target="sendConsole"
                    class="inline-flex items-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest/90 disabled:opacity-60">
                <span wire:loading.remove wire:target="sendConsole">{{ __('Send request') }}</span>
                <span wire:loading wire:target="sendConsole">{{ __('Invoking…') }}</span>
            </button>
            <p class="text-[11px] text-brand-moss/60">{{ __('Recorded as a test invocation — it also shows on the Logs tab.') }}</p>
        </div>

        @if ($consoleResult !== null)
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                @if (! $consoleResult['ok'])
                    <p class="text-sm text-rose-700">{{ $consoleResult['error'] ?? __('The request failed.') }}</p>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold',
                            'bg-brand-forest/15 text-brand-forest' => $consoleResult['success'],
                            'bg-rose-100 text-rose-700' => ! $consoleResult['success'],
                        ])>{{ $consoleResult['success'] ? __('OK') : __('Error') }}</span>
                        @if ($consoleResult['status'])
                            <span class="font-mono text-xs text-brand-moss">HTTP {{ $consoleResult['status'] }}</span>
                        @endif
                        <span class="text-xs text-brand-moss">{{ $consoleResult['duration'] }}ms</span>
                    </div>
                    @if (trim((string) $consoleResult['excerpt']) !== '')
                        <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-brand-sand/40 p-3 text-[11px] leading-relaxed text-brand-ink">{{ $consoleResult['excerpt'] }}</pre>
                    @endif
                    @if (count($consoleResult['logs']) > 0)
                        <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-brand-ink p-3 text-[11px] leading-relaxed text-brand-cream">{{ implode("\n", $consoleResult['logs']) }}</pre>
                    @endif
                @endif
            </div>
        @endif
    @endif
</div>
