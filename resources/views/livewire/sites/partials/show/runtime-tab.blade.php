                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Runtime target') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('The latest managed deploy details for this runtime target.') }}</p>
                            </div>
                            <span class="ml-auto inline-flex shrink-0 items-center self-center rounded-full bg-brand-sand/40 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                                {{ $site->runtimeTargetLabel() }}
                            </span>
                        </div>

                        <div class="space-y-4 px-6 py-6 sm:px-7">
                        <dl class="grid gap-4 sm:grid-cols-3">
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Platform') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst((string) ($runtimeTarget['platform'] ?? 'unknown')) }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Mode') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst((string) ($runtimeTarget['mode'] ?? 'unknown')) }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Status') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst(str_replace('_', ' ', (string) ($runtimeTarget['status'] ?? 'unknown'))) }}</dd>
                            </div>
                        </dl>

                        @if ($preflightChecks->isNotEmpty())
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <h4 class="text-sm font-semibold text-brand-ink">{{ __('Deployment foundation') }}</h4>
                                <dl class="mt-3 grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Current revision') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $foundationStatus['current_runtime_revision'] ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Last applied revision') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $foundationStatus['last_applied_runtime_revision'] ?? __('Not applied yet') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Drift') }}</dt>
                                        <dd class="mt-2 text-sm {{ $runtimeDrifted ? 'text-amber-700' : 'text-emerald-700' }}">{{ $runtimeDrifted ? __('Detected') : __('In sync') }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                    @foreach ($preflightChecks as $check)
                                        <div class="rounded-lg border px-3 py-2 text-sm {{ ($check['level'] ?? 'ok') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : (($check['level'] ?? 'ok') === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">
                                            <span class="font-medium">{{ str($check['key'] ?? 'check')->headline() }}</span>
                                            <p class="mt-1 text-xs leading-5">{{ $check['message'] ?? '' }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($runtimePublication !== [])
                            <dl class="grid gap-4 sm:grid-cols-3">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Publication status') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ ucfirst((string) ($runtimePublication['status'] ?? 'pending')) }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Hostname') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Published URL') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['url'] ?? '—' }}</dd>
                                </div>
                            </dl>
                        @endif

                        @if ($site->usesFunctionsRuntime())
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Runtime') }}</dt>
                                    <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $serverlessRuntime['runtime'] ?? '—' }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Entrypoint') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['entrypoint'] ?? '—' }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Revision') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['last_revision_id'] ?? __('Not deployed yet') }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Latest artifact') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['artifact_path'] ?? __('Not built yet') }}</dd>
                                </div>
                                @if (! empty($serverlessRuntime['function_arn']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Function ARN') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['function_arn'] }}</dd>
                                    </div>
                                @endif
                                @if (! empty($serverlessRuntime['function_url']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Function URL') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['function_url'] }}</dd>
                                    </div>
                                @endif
                                @if (! empty($serverlessRuntime['action_url']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Published action URL') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $serverlessRuntime['action_url'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @elseif ($site->usesDockerRuntime())
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Compose file') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ isset($dockerRuntime['compose_yaml']) ? __('Available') : __('Not generated yet') }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Dockerfile') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ isset($dockerRuntime['dockerfile']) ? __('Available') : __('Not generated yet') }}</dd>
                                </div>
                                @if (! empty($dockerRuntime['workspace_path']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Local workspace') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $dockerRuntime['workspace_path'] }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 space-y-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Docker discovery') }}</p>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('Saved from the live runtime so hostname, IP, and identity stay referenceable.') }}</p>
                                        </div>
                                        @if (! empty($dockerRuntimeDetails['collected_at']))
                                            <p class="font-mono text-[11px] text-brand-mist">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                                        @endif
                                    </div>

                                    <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Hostname') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Container IP') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_ip'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Container name') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_name'] ?? '—' }}</dd>
                                        </div>
                                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Service') }}</dt>
                                            <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['docker_service'] ?? '—' }}</dd>
                                        </div>
                                    </dl>

                                    @if ($dockerContainers->isNotEmpty())
                                        <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                                            <div class="border-b border-brand-ink/10 px-4 py-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Containers') }}</p>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="min-w-full divide-y divide-brand-ink/10 text-left">
                                                    <thead class="bg-brand-sand/30">
                                                        <tr>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Name') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Service') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Hostname') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('IP') }}</th>
                                                            <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('State') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-brand-ink/8 bg-white">
                                                        @foreach ($dockerContainers as $container)
                                                            <tr>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['name'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['service'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['orb_hostname'] ?? $container['hostname'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['ipv4'] ?? '—' }}</td>
                                                                <td class="px-4 py-3 font-mono text-sm text-brand-moss">{{ $container['state'] ?? '—' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @else
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Namespace') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $kubernetesRuntime['namespace'] ?? __('default') }}</dd>
                                </div>
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Manifest') }}</dt>
                                    <dd class="mt-2 text-sm text-brand-ink">{{ isset($kubernetesRuntime['manifest_yaml']) ? __('Generated') : __('Not generated yet') }}</dd>
                                </div>
                                @if (! empty($kubernetesRuntime['workspace_path']))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Local workspace') }}</dt>
                                        <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $kubernetesRuntime['workspace_path'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @endif

                        @if ($site->usesLocalDockerHostRuntime())
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 space-y-4">
                                <div>
                                    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Runtime controls') }}</h4>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Manage the local runtime backing this site directly from here.') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">{{ __('Rebuild') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('start')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Start') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('stop')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Stop') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('restart')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('inspect')" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-800 hover:bg-sky-100">{{ __('Refresh details') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('errors')" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Errors') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('status')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Status') }}</button>
                                    <button type="button" wire:click="runRuntimeAction('logs')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Logs') }}</button>
                                    <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this site?')), @js(__('Destroy runtime')), true)" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">{{ __('Destroy') }}</button>
                                </div>

                                @if ($runtimeErrorConsole)
                                    @include('livewire.partials.deployment-activity-console', [
                                        'title' => __('Runtime errors'),
                                        'meta' => $runtimeErrorConsole['meta'],
                                        'transcript' => $runtimeErrorConsole['transcript'],
                                        'maxHeight' => '20rem',
                                    ])
                                @endif

                                @if ($runtimeOperationConsoles->isNotEmpty())
                                    <div class="space-y-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Recent runtime operations') }}</p>
                                        @foreach ($runtimeOperationConsoles as $runtimeConsole)
                                            @include('livewire.partials.deployment-activity-console', [
                                                'title' => $runtimeConsole['title'],
                                                'meta' => $runtimeConsole['meta'],
                                                'transcript' => $runtimeConsole['transcript'],
                                                'maxHeight' => '18rem',
                                            ])
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                        </div>
                    </section>
