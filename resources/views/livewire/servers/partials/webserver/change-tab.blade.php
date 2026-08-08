        <div class="{{ $card }}">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-arrow-path"
                :title="__('Switch webserver')"
                :note="__('One webserver per box. Switching reprovisions all sites under the new webserver — parallel install on :8080, then a brief service-swap to :80 (under 1 second blip).')"
                class="border-b border-brand-ink/10"
            />

            @if ($inflightSwitch)
                <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-[11px] text-amber-900 sm:px-5">
                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                    {{ __('A webserver switch is currently running. Switch buttons are disabled until it settles — watch the progress banner at the top of this page.') }}
                </p>
            @endif

            <div class="grid gap-2 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
                @foreach ($webserverCatalog as $key => $info)
                    @continue($key === $activeWebserver)
                    @php
                        $isBlocked = $preflight->isBlocked($server, $key);
                        $isComingSoon = ! empty($info['coming_soon']);
                    @endphp
                    <div class="rounded-xl border border-brand-ink/10 bg-white p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-start gap-2">
                                <x-dynamic-component :component="$info['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-brand-forest" />
                                <p class="min-w-0 font-semibold text-brand-ink">{{ $info['label'] }}</p>
                            </div>
                            @if ($isComingSoon)
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-brand-sand/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('Soon') }}
                                </span>
                            @endif
                        </div>

                        @include('livewire.servers.partials.webserver._switch-target-action', [
                            'actionInFlight' => $actionInFlight,
                        ])
                    </div>
                @endforeach
            </div>
        </div>
