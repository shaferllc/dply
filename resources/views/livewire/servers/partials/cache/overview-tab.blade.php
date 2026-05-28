            @if ($cacheServices->isEmpty())
                <div class="{{ $card }} p-6 sm:p-8">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('No cache services installed') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Pick an engine from the tabs above to install one. You can install multiple engines side-by-side — for example Redis for queues and Memcached for app cache.') }}
                    </p>
                </div>
            @else
                @foreach ($cacheServices as $row)
                    @php
                        $engineLabel = $engineLabels[$row->engine] ?? ucfirst($row->engine);
                        $rowInFlight = in_array($row->status, [
                            \App\Models\ServerCacheService::STATUS_PENDING,
                            \App\Models\ServerCacheService::STATUS_INSTALLING,
                            \App\Models\ServerCacheService::STATUS_UNINSTALLING,
                        ], true);
                    @endphp
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="text-base font-semibold text-brand-ink">
                                {{ $engineLabel }}
                                @if (! $row->isDefaultInstance())
                                    <span class="text-brand-mist">/</span>
                                    <span class="font-mono text-sm text-brand-moss">{{ $row->name }}</span>
                                @endif
                            </h2>
                            @switch($row->status)
                                @case(\App\Models\ServerCacheService::STATUS_RUNNING)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Running') }}</span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_STOPPED)
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Stopped') }}</span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_PENDING)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                        <x-spinner variant="forest" /> {{ __('Queued…') }}
                                    </span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_INSTALLING)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                        <x-spinner variant="forest" /> {{ __('Installing…') }}
                                    </span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_UNINSTALLING)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                        <x-spinner variant="forest" /> {{ __('Uninstalling…') }}
                                    </span>
                                    @break
                                @case(\App\Models\ServerCacheService::STATUS_FAILED)
                                    <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700" title="{{ $row->error_message }}">{{ __('Failed') }}</span>
                                    @break
                                @default
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-medium text-brand-ink">{{ ucfirst($row->status) }}</span>
                            @endswitch
                            <a
                                href="#"
                                wire:click.prevent="setWorkspaceTab('{{ $row->engine }}')"
                                class="ml-auto text-xs font-medium text-brand-forest hover:underline"
                            >{{ __('Open :engine workspace →', ['engine' => $engineLabel]) }}</a>
                        </div>

                        <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                                <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $row->version ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                                <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $row->port }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                <dd class="mt-1 text-sm text-brand-ink">{{ ucfirst($row->status) }}</dd>
                            </div>
                        </dl>

                        @if ($row->status === \App\Models\ServerCacheService::STATUS_FAILED && filled($row->error_message))
                            <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">
                                {{ $row->error_message }}
                            </p>
                        @endif

                        @php $stats = $cacheStatsByInstance[$row->engine][$row->name] ?? []; @endphp
                        @if (! empty($stats))
                            <dl class="mt-4 grid gap-4 rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ($stats as $label => $value)
                                    <div>
                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                                        <dd class="mt-1 font-mono text-xs text-brand-ink">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @endforeach

                @foreach ($cacheServices as $row)
                    @if (\App\Models\ServerCacheService::engineSupportsAuth($row->engine) || $row->engine === 'memcached')
                        @include('livewire.servers.partials.cache-connection-snippet', [
                            'cacheService' => $row,
                            'card' => $card,
                            'engineLabels' => $engineLabels,
                        ])
                    @endif
                @endforeach
            @endif
