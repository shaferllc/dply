@php
    $sourceCatalog = [];
    foreach ((array) config('server_logs.sources', []) as $srcKey => $srcMeta) {
        $sourceCatalog[(string) $srcKey] = (string) ($srcMeta['label'] ?? $srcKey);
    }
    $agentRunning = (bool) $server->logAgent?->isRunning();
@endphp

<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Add-on') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Log alerts') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Get notified when shipped logs cross a threshold — "more than N error lines in 5 minutes", or "a line matching OOMKilled appeared". Routed through your configured notification channels.') }}
                </p>
            </div>
            @if ($alertingAvailable)
                <button
                    type="button"
                    wire:click="openLogAlertForm"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-brand-forest px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-forest/90"
                >
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('New alert') }}
                </button>
            @endif
        </div>

        <div class="space-y-5 px-6 py-5 sm:px-7">
            {{-- Plan gate --}}
            @unless ($alertingAvailable)
                <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/5 px-5 py-6 text-center">
                    <x-heroicon-o-lock-closed class="mx-auto h-7 w-7 text-brand-sage" aria-hidden="true" />
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __('Log alerting is a paid feature') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-brand-moss">
                        {{ __('Upgrade to the Pro or Business plan to set up threshold and pattern alerts on your shipped logs.') }}
                    </p>
                    <a href="{{ route('billing.show', ['organization' => $server->organization]) }}" wire:navigate class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-forest hover:bg-brand-sand/30">
                        {{ __('View plans') }} <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                    </a>
                </div>
            @else
                {{-- Shipping prerequisite hint --}}
                @unless ($agentRunning)
                    <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
                        {{ __('This server is not shipping logs yet, so alerts have nothing to evaluate. Enable log shipping on the dply Logs tab first.') }}
                    </div>
                @endunless

                {{-- Create / edit form --}}
                @if ($logAlertFormOpen)
                    <form wire:submit="saveLogAlertRule" class="space-y-4 rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] p-5">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-brand-ink">
                                {{ $logAlertEditingId ? __('Edit alert') : __('New alert') }}
                            </h4>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-brand-moss">{{ __('Name') }}</label>
                            <input type="text" wire:model="logAlertName" placeholder="{{ __('e.g. Error spike') }}"
                                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-sage focus:ring-brand-sage" />
                            @error('logAlertName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Type --}}
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" wire:click="$set('logAlertType', 'rate')"
                                class="rounded-lg border px-3 py-2 text-left text-sm transition {{ $logAlertType === 'rate' ? 'border-brand-sage/50 bg-brand-sage/10' : 'border-brand-ink/10 bg-white hover:bg-brand-sand/20' }}">
                                <span class="font-semibold text-brand-ink">{{ __('Rate') }}</span>
                                <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Count over a window') }}</span>
                            </button>
                            <button type="button" wire:click="$set('logAlertType', 'pattern')"
                                class="rounded-lg border px-3 py-2 text-left text-sm transition {{ $logAlertType === 'pattern' ? 'border-brand-sage/50 bg-brand-sage/10' : 'border-brand-ink/10 bg-white hover:bg-brand-sand/20' }}">
                                <span class="font-semibold text-brand-ink">{{ __('Pattern') }}</span>
                                <span class="mt-0.5 block text-xs text-brand-moss">{{ __('A matching line appeared') }}</span>
                            </button>
                        </div>

                        {{-- Facets --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-brand-moss">{{ __('Source') }}</label>
                                <select wire:model="logAlertSource" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-sage focus:ring-brand-sage">
                                    <option value="">{{ __('Any source') }}</option>
                                    @foreach ($sourceCatalog as $srcKey => $srcLabel)
                                        <option value="{{ $srcKey }}">{{ $srcLabel }}</option>
                                    @endforeach
                                </select>
                                @error('logAlertSource') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-brand-moss">{{ __('Level') }}</label>
                                <input type="text" wire:model="logAlertLevel" placeholder="{{ __('e.g. error (any if blank)') }}"
                                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-sage focus:ring-brand-sage" />
                                @error('logAlertLevel') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-brand-moss">
                                {{ __('Message contains') }}
                                @if ($logAlertType === 'pattern') <span class="text-rose-500">*</span> @endif
                            </label>
                            <input type="text" wire:model="logAlertSearch" placeholder="{{ __('e.g. OOMKilled, segfault, panic') }}"
                                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-sage focus:ring-brand-sage" />
                            @error('logAlertSearch') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Thresholds --}}
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div @class(['opacity-50' => $logAlertType === 'pattern'])>
                                <label class="block text-xs font-semibold text-brand-moss">{{ __('Threshold (lines)') }}</label>
                                <input type="number" min="1" wire:model="logAlertThreshold" @disabled($logAlertType === 'pattern')
                                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-sage focus:ring-brand-sage" />
                                @error('logAlertThreshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-brand-moss">{{ __('Window (minutes)') }}</label>
                                <input type="number" min="1" wire:model="logAlertWindowMinutes"
                                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-sage focus:ring-brand-sage" />
                                @error('logAlertWindowMinutes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-brand-moss">{{ __('Cooldown (minutes)') }}</label>
                                <input type="number" min="0" wire:model="logAlertCooldownMinutes"
                                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-sage focus:ring-brand-sage" />
                                @error('logAlertCooldownMinutes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($logAlertType === 'pattern')
                            <p class="text-xs text-brand-moss">{{ __('Pattern alerts fire as soon as one matching line is shipped.') }}</p>
                        @endif

                        <div class="flex items-center gap-3 border-t border-brand-ink/10 pt-4">
                            <button type="submit" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-forest/90 disabled:opacity-50">
                                <x-heroicon-o-check class="h-4 w-4" aria-hidden="true" />
                                {{ $logAlertEditingId ? __('Save changes') : __('Create alert') }}
                            </button>
                            <button type="button" wire:click="cancelLogAlertForm"
                                class="text-sm font-semibold text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                        </div>
                    </form>
                @endif

                {{-- Rules list --}}
                @if ($rules->isEmpty())
                    @unless ($logAlertFormOpen)
                        <div class="px-2 py-8 text-center text-sm text-brand-moss">
                            <x-heroicon-o-bell-slash class="mx-auto h-6 w-6 text-brand-ink/30" aria-hidden="true" />
                            <p class="mt-2 font-medium text-brand-ink">{{ __('No alerts yet') }}</p>
                            <p class="mt-0.5">{{ __('Create one to be notified when something looks wrong in your logs.') }}</p>
                        </div>
                    @endunless
                @else
                    <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                        @foreach ($rules as $rule)
                            @php
                                $facetBits = array_filter([
                                    $rule->source ? __('source :s', ['s' => $rule->source]) : null,
                                    $rule->level ? __('level :l', ['l' => $rule->level]) : null,
                                    $rule->search ? '“'.$rule->search.'”' : null,
                                ]);
                            @endphp
                            <div class="flex items-start gap-4 border-b border-brand-ink/5 px-4 py-3.5 last:border-b-0" wire:key="log-alert-{{ $rule->id }}">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $rule->enabled ? 'bg-emerald-500' : 'bg-brand-ink/20' }}" title="{{ $rule->enabled ? __('Active') : __('Paused') }}"></span>
                                        <p class="truncate text-sm font-semibold text-brand-ink">{{ $rule->name }}</p>
                                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $rule->type }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-brand-moss">
                                        @if ($rule->type === 'pattern')
                                            {{ __('A matching line appears within :mins min', ['mins' => $rule->window_minutes]) }}
                                        @else
                                            {{ __('≥ :threshold lines in :mins min', ['threshold' => $rule->threshold, 'mins' => $rule->window_minutes]) }}
                                        @endif
                                        @if ($facetBits) · {{ implode(' · ', $facetBits) }} @endif
                                        · {{ __('cooldown :mins min', ['mins' => $rule->cooldown_minutes]) }}
                                    </p>
                                    <p class="mt-1 text-[11px] text-brand-mist">
                                        @if ($rule->last_evaluated_at)
                                            {{ __('Last checked :ago', ['ago' => $rule->last_evaluated_at->diffForHumans()]) }}
                                            @if ($rule->last_count !== null) · {{ __(':n matched', ['n' => $rule->last_count]) }} @endif
                                        @else
                                            {{ __('Not evaluated yet') }}
                                        @endif
                                        @if ($rule->last_fired_at) · <span class="text-amber-700">{{ __('last fired :ago', ['ago' => $rule->last_fired_at->diffForHumans()]) }}</span> @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" wire:click="toggleLogAlertRule('{{ $rule->id }}')" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-moss hover:bg-brand-sand/30 hover:text-brand-ink" title="{{ $rule->enabled ? __('Pause') : __('Enable') }}">
                                        @if ($rule->enabled)
                                            <x-heroicon-o-pause class="h-4 w-4" aria-hidden="true" />
                                        @else
                                            <x-heroicon-o-play class="h-4 w-4" aria-hidden="true" />
                                        @endif
                                    </button>
                                    <button type="button" wire:click="editLogAlertRule('{{ $rule->id }}')" class="rounded-lg px-2 py-1 text-brand-moss hover:bg-brand-sand/30 hover:text-brand-ink" title="{{ __('Edit') }}">
                                        <x-heroicon-o-pencil-square class="h-4 w-4" aria-hidden="true" />
                                    </button>
                                    <button type="button" wire:click="deleteLogAlertRule('{{ $rule->id }}')" wire:confirm="{{ __('Delete this alert rule?') }}" class="rounded-lg px-2 py-1 text-rose-600 hover:bg-rose-50" title="{{ __('Delete') }}">
                                        <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endunless
        </div>
    </section>
</div>
