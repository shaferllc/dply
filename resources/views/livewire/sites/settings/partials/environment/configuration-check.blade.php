    {{-- Configuration check — surfaced at the very top so risky settings
         (debug-in-prod, empty APP_KEY, plaintext URLs, placeholder secrets)
         are the first thing you see and can jump straight to fixing. Each
         keyed warning filters the list to that variable on click. --}}
    @if ($envWarnings !== [])
        @php $hasDanger = collect($envWarnings)->contains(fn ($w) => $w['level'] === 'danger'); @endphp
        <div class="flex items-start gap-3 {{ $hasDanger ? 'bg-rose-50/60' : 'bg-amber-50/60' }} px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $hasDanger ? 'bg-rose-100 text-rose-700 ring-rose-200' : 'bg-amber-100 text-amber-700 ring-amber-200' }}">
                    <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $hasDanger ? 'text-rose-700' : 'text-amber-700' }}">{{ __('Configuration check') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold {{ $hasDanger ? 'text-rose-900' : 'text-amber-950' }}">
                        {{ trans_choice('{1} :count configuration warning|[2,*] :count configuration warnings', count($envWarnings), ['count' => count($envWarnings)]) }}
                    </h3>
                    <ul class="mt-1.5 space-y-1.5">
                        @foreach ($envWarnings as $w)
                            <li class="flex items-start justify-between gap-3 text-sm {{ $w['level'] === 'danger' ? 'text-rose-800' : ($w['level'] === 'warn' ? 'text-amber-900' : 'text-brand-moss') }}">
                                <span class="flex items-start gap-2">
                                    <span class="mt-1.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $w['level'] === 'danger' ? 'bg-rose-600' : ($w['level'] === 'warn' ? 'bg-amber-500' : 'bg-brand-mist') }}"></span>
                                    <span>{{ $w['message'] }}</span>
                                </span>
                                @if (! empty($w['key']) && $envAdvanced)
                                    <span class="flex shrink-0 items-center gap-1.5">
                                        <button type="button" wire:click="openFixEnvVar(@js($w['key']))" class="whitespace-nowrap rounded-md border border-black/10 bg-white/60 px-2 py-0.5 text-[11px] font-semibold underline-offset-2 hover:bg-white hover:underline" title="{{ __('Fix :key', ['key' => $w['key']]) }}">
                                            {{ __('Fix :key', ['key' => $w['key']]) }}
                                        </button>
                                        @if ($canIgnoreEnvWarnings)
                                            <button type="button" wire:click="ignoreEnvWarning(@js($w['key']))" class="whitespace-nowrap rounded-md border border-black/10 bg-white/60 px-2 py-0.5 text-[11px] font-semibold text-brand-mist underline-offset-2 hover:bg-white hover:underline" title="{{ __('Suppress this warning') }}">
                                                {{ __('Ignore') }}
                                            </button>
                                        @endif
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
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
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-black/10 bg-white/70 px-2.5 py-1 text-[11px] font-semibold text-brand-ink underline-offset-2 transition hover:bg-white hover:underline">
                                    <x-dynamic-component :component="$meta['icon']" class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
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
