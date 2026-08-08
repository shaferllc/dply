    {{-- Configuration check — surfaced at the very top so risky settings
         (debug-in-prod, empty APP_KEY, plaintext URLs, placeholder secrets)
         are the first thing you see and can jump straight to fixing. Each
         keyed warning filters the list to that variable on click. --}}
    @if ($envWarnings !== [])
        @php $hasDanger = collect($envWarnings)->contains(fn ($w) => $w['level'] === 'danger'); @endphp
        {{-- "Configuration check" eyebrow dropped: this block already sits under
             the panel's own "Needs attention" header, and the heading below
             names the finding. --}}
        @php
            $dangerCount = collect($envWarnings)->where('level', 'danger')->count();
            $warnCount = count($envWarnings) - $dangerCount;
        @endphp
        {{-- Tint lives on the per-row severity rail, not the whole block. A
             full-bleed rose wash over seven findings read as one undifferentiated
             wall of alarm and buried which ones actually break the request path. --}}
        @php
            // Keys the panel can settle without asking. Gated on the component
            // actually exposing the bulk action, since this partial is shared
            // by hosts that don't all carry the env-fix concern.
            $autoFixableKeys = method_exists($this, 'autoFixableEnvWarningKeys')
                ? $this->autoFixableEnvWarningKeys(
                    app(\App\Services\Sites\SiteEnvValidator::class),
                    app(\App\Services\Sites\DotEnvFileParser::class),
                )
                : [];
        @endphp
        <div class="px-5 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <x-heroicon-o-shield-exclamation class="h-4 w-4 shrink-0 {{ $hasDanger ? 'text-rose-600' : 'text-amber-600' }}" />
                    <h3 class="text-xs font-semibold text-brand-ink">
                        {{ trans_choice('{1} :count configuration warning|[2,*] :count configuration warnings', count($envWarnings), ['count' => count($envWarnings)]) }}
                    </h3>
                    {{-- Severity split up front: "7 warnings" alone doesn't say how
                         many are boot-breaking versus merely untidy. --}}
                    <span class="flex items-center gap-1.5 text-[11px] font-medium">
                        @if ($dangerCount > 0)
                            <span class="rounded-full bg-rose-100 px-1.5 py-0.5 tabular-nums text-rose-700">{{ __(':n breaking', ['n' => $dangerCount]) }}</span>
                        @endif
                        @if ($warnCount > 0)
                            <span class="rounded-full bg-amber-100 px-1.5 py-0.5 tabular-nums text-amber-800">{{ __(':n advisory', ['n' => $warnCount]) }}</span>
                        @endif
                    </span>

                    @if ($autoFixableKeys !== [] && $envAdvanced)
                        {{-- One click for the whole set. Most of these findings —
                             the broadcaster credentials especially — are values
                             dply can simply generate, so making the operator walk
                             a modal per key was busywork. Only keys with a known
                             good value are touched; a missing DB_PASSWORD is left
                             for the operator rather than filled with a guess. --}}
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('fixAllEnvWarnings', [], @js(__('Fix :count variable(s)?', ['count' => count($autoFixableKeys)])), @js(__('dply will set a known good value for each — generating fresh secrets where the value is yours to choose. Variables only you can know (passwords, API credentials) are left untouched.')), @js(__('Fix them')), false, @js([['label' => __('Will be set'), 'value' => implode(', ', $autoFixableKeys), 'mono' => true]]))"
                            wire:loading.attr="disabled"
                            wire:target="fixAllEnvWarnings"
                            class="dply-btn dply-btn-xs dply-btn-primary ml-auto"
                            title="{{ __('Set a known good value for: :keys', ['keys' => implode(', ', $autoFixableKeys)]) }}"
                        >
                            <x-heroicon-o-sparkles class="h-3.5 w-3.5" />
                            {{ __('Fix :count', ['count' => count($autoFixableKeys)]) }}
                        </button>
                    @endif
                </div>

                {{-- One row per finding: severity rail, key, one-line consequence,
                     actions. Anything longer than the line goes behind the row's
                     own disclosure rather than wrapping to three lines and pushing
                     its own buttons out of alignment. --}}
                @php
                    // Collapse findings that say the same thing about different
                    // keys into one row. The three broadcaster credentials are a
                    // single problem with a single fix, and printing the identical
                    // sentence (and its identical "How to fix") three times is
                    // what made this panel read as a wall.
                    $warningGroups = [];
                    foreach ($envWarnings as $w) {
                        $key = (string) ($w['key'] ?? '');
                        $body = $key !== '' && str_starts_with($w['message'], $key.' ')
                            ? substr($w['message'], strlen($key) + 1)
                            : $w['message'];
                        $sig = $w['level'].'|'.($w['detail'] ?? '').'|'.$body;

                        if (! isset($warningGroups[$sig])) {
                            $warningGroups[$sig] = [
                                'level' => $w['level'],
                                'body' => $body,
                                'detail' => $w['detail'] ?? null,
                                'keys' => [],
                            ];
                        }
                        if ($key !== '') {
                            $warningGroups[$sig]['keys'][] = $key;
                        }
                    }
                    $warningGroups = array_values($warningGroups);
                @endphp
                <ul class="mt-2 divide-y divide-brand-ink/5">
                    @foreach ($warningGroups as $g)
                        @php
                            $isDanger = $g['level'] === 'danger';
                            $isWarn = $g['level'] === 'warn';
                            $gKeys = $g['keys'];
                            $gMulti = count($gKeys) > 1;
                        @endphp
                        {{-- Square rows, not rounded: a radius on a row that also
                             carries a coloured left rail rounds the rail into a
                             lozenge and reads as a stray blob. Note the rail sets
                             `border-l-<colour>`, not `border-<colour>` — the
                             latter also colours the divide-y rule, which drew a
                             red line between every row. --}}
                        <li @class([
                            'flex items-start gap-3 border-l-2 py-2 pl-3 pr-1 transition-colors hover:bg-brand-sand/20',
                            'border-l-rose-500' => $isDanger,
                            'border-l-amber-500' => $isWarn,
                            'border-l-brand-mist/50' => ! $isDanger && ! $isWarn,
                        ])>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs leading-5 text-brand-ink">
                                    @foreach ($gKeys as $gk)
                                        <span class="font-mono text-[11px] font-semibold {{ $isDanger ? 'text-rose-700' : ($isWarn ? 'text-amber-800' : 'text-brand-moss') }}">{{ $gk }}</span>{{ ! $loop->last ? ', ' : '' }}
                                    @endforeach
                                    @if ($gKeys !== [])
                                        <span class="text-brand-mist" aria-hidden="true">·</span>
                                    @endif
                                    {{ $gMulti ? \Illuminate\Support\Str::replaceFirst('is ', 'are ', $g['body']) : $g['body'] }}
                                </p>
                                @if ($g['detail'])
                                    <details class="group/detail mt-0.5">
                                        <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-[11px] font-medium text-brand-moss hover:text-brand-ink [&::-webkit-details-marker]:hidden">
                                            <x-heroicon-o-chevron-right class="h-3 w-3 transition-transform group-open/detail:rotate-90" />
                                            {{ __('How to fix') }}
                                        </summary>
                                        <p class="mt-1 pl-4 text-[11px] leading-relaxed text-brand-moss">{{ $g['detail'] }}</p>
                                    </details>
                                @endif
                            </div>

                            @if ($gKeys !== [] && $envAdvanced)
                                {{-- Fixed-width action cluster. The old labels embedded
                                     the key ("Fix REVERB_APP_SECRET"), so every row's
                                     buttons were a different width and none lined up. --}}
                                <span class="flex shrink-0 items-center gap-1">
                                    @if ($gMulti && method_exists($this, 'fixEnvWarningKeys'))
                                        <button type="button" wire:click="fixEnvWarningKeys(@js($gKeys))"
                                            wire:loading.attr="disabled" wire:target="fixEnvWarningKeys"
                                            class="dply-btn dply-btn-xs dply-btn-outline"
                                            title="{{ __('Fix all :count: :keys', ['count' => count($gKeys), 'keys' => implode(', ', $gKeys)]) }}">
                                            {{ __('Fix all :count', ['count' => count($gKeys)]) }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="openFixEnvVar(@js($gKeys[0]))"
                                            class="dply-btn dply-btn-xs dply-btn-outline"
                                            title="{{ __('Fix :key', ['key' => $gKeys[0]]) }}">
                                            {{ __('Fix') }}
                                            <span class="sr-only">{{ $gKeys[0] }}</span>
                                        </button>
                                    @endif
                                    @if ($canIgnoreEnvWarnings)
                                        <button type="button" wire:click="ignoreEnvWarning(@js($gKeys[0]))"
                                            class="dply-btn dply-btn-xs dply-btn-ghost"
                                            title="{{ $gMulti ? __('Suppress the :key warning', ['key' => $gKeys[0]]) : __('Suppress this warning') }}">
                                            {{ __('Ignore') }}
                                            <span class="sr-only">{{ $gKeys[0] }}</span>
                                        </button>
                                    @endif
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="mt-2">
                    @if ($suppressedEnvWarningCount > 0 && $canIgnoreEnvWarnings)
                        <p class="mt-2 text-[11px] text-brand-mist">
                            {{ trans_choice('{1} :count warning suppressed.|[2,*] :count warnings suppressed.', $suppressedEnvWarningCount, ['count' => $suppressedEnvWarningCount]) }}
                            @foreach ($suppressedEnvWarningKeys as $sk)
                                <button type="button" wire:click="unignoreEnvWarning(@js($sk))" class="ml-1 font-semibold hover:underline" title="{{ __('Re-enable :key warning', ['key' => $sk]) }}">{{ $sk }}</button>
                            @endforeach
                        </p>
                    @endif

                    {{-- Manage the resource behind these warnings without leaving the
                         page. A warning whose key belongs to a managed binding type
                         (DB_* → database, REDIS_*/CACHE_*/QUEUE_* → Redis, …) gets a
                         button that opens that resource's attach/provision modal —
                         attaching auto-discovers what already exists on the server, so
                         e.g. an empty DB_PASSWORD is fixed by linking a managed database
                         instead of hand-typing credentials. --}}
                    @php
                        $resourceActionMap = [
                            'database' => ['label' => __('database'), 'icon' => 'heroicon-m-circle-stack', 'prefixes' => ['DB_', 'DATABASE_URL']],
                            'redis' => ['label' => __('Redis'), 'icon' => 'heroicon-m-bolt', 'prefixes' => ['REDIS_', 'CACHE_', 'QUEUE_', 'SESSION_']],
                            'storage' => ['label' => __('object storage'), 'icon' => 'heroicon-m-archive-box', 'prefixes' => ['AWS_', 'FILESYSTEM_', 'S3_']],
                            'mail' => ['label' => __('mail'), 'icon' => 'heroicon-m-envelope', 'prefixes' => ['MAIL_']],
                            'broadcasting' => ['label' => __('broadcasting'), 'icon' => 'heroicon-m-signal', 'prefixes' => ['PUSHER_', 'REVERB_', 'ABLY_', 'BROADCAST_']],
                        ];
                        $warningKeys = collect($envWarnings)->pluck('key')->filter()->all();
                        $resourceActions = [];
                        foreach ($resourceActionMap as $type => $meta) {
                            foreach ($warningKeys as $wk) {
                                foreach ($meta['prefixes'] as $pfx) {
                                    if (str_ends_with($pfx, '_') ? str_starts_with((string) $wk, $pfx) : (string) $wk === $pfx) {
                                        $resourceActions[$type] = $meta;
                                        continue 3;
                                    }
                                }
                            }
                        }
                    @endphp
                    @if ($resourceActions !== [])
                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-black/5 pt-3">
                            <span class="text-[11px] font-medium text-brand-moss">{{ __('Manage the resource instead:') }}</span>
                            @foreach ($resourceActions as $type => $meta)
                                <button type="button" wire:click="openBindingModal(@js($type))"
                                    class="dply-btn dply-btn-xs dply-btn-outline whitespace-nowrap">
                                    <x-dynamic-component :component="$meta['icon']" class="h-3.5 w-3.5 text-brand-moss" />
                                    {{ __('Manage :resource', ['resource' => $meta['label']]) }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
    @endif
    @if ($envWarnings === [] && $suppressedEnvWarningCount > 0 && $canIgnoreEnvWarnings)
        <div class="flex flex-wrap items-center justify-between gap-2 bg-brand-sand/10 px-5 py-4 text-sm text-brand-moss">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-no-symbol class="h-4 w-4 text-brand-mist" />
                {{ trans_choice('{1} :count configuration warning is suppressed.|[2,*] :count configuration warnings are suppressed.', $suppressedEnvWarningCount, ['count' => $suppressedEnvWarningCount]) }}
            </span>
            <span class="flex flex-wrap gap-2">
                @foreach ($suppressedEnvWarningKeys as $sk)
                    <button type="button" wire:click="unignoreEnvWarning(@js($sk))" class="font-semibold text-brand-forest hover:underline">{{ $sk }}</button>
                @endforeach
            </span>
        </div>
    @endif
