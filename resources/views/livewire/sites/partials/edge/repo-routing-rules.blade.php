@php
    $config = is_array($edgeBuildRepoConfig ?? null) ? $edgeBuildRepoConfig : null;
    $redirects = is_array($config['redirects'] ?? null) ? $config['redirects'] : [];
    $rewrites = is_array($config['rewrites'] ?? null) ? $config['rewrites'] : [];
    $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
    $hasRules = $redirects !== [] || $rewrites !== [] || $headers !== [];
    $sourcePath = is_string($config['source_path'] ?? null) ? $config['source_path'] : 'dply.yaml';
@endphp

<section id="edge-build-routing" class="scroll-mt-24 dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Redirects, rewrites & headers') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Read-only view of routing rules from your repo config. Edit :file in Git — the dashboard cannot change these in v1.', ['file' => $sourcePath]) }}</p>
            </div>
            @if ($config !== null)
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                    {{ __('From repo') }}
                </span>
            @endif
        </div>
    </div>

    @if ($config === null)
        <div class="px-6 py-8 text-sm text-brand-moss sm:px-8">
            <p>{{ __('No :file loaded yet. Commit a config file to your repository and deploy — rules appear here after the build parses it.', ['file' => 'dply.yaml']) }}</p>
            <p class="mt-2 text-xs">{{ __('Use `dply edge lint` locally or check the build log if a deploy fails on config validation.') }}</p>
            @include('livewire.sites.partials.edge.dply-yaml-starter-examples')
        </div>
    @elseif (! $hasRules)
        <div class="px-6 py-8 text-sm text-brand-moss sm:px-8">
            <p>{{ __(':file was loaded on the last deploy, but it defines no redirects, rewrites, or header rules yet.', ['file' => $sourcePath]) }}</p>
            @include('livewire.sites.partials.edge.dply-yaml-starter-examples')
        </div>
    @else
        <div class="divide-y divide-brand-ink/8">
            @if ($redirects !== [])
                <div class="px-6 py-4 sm:px-8">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Redirects') }}</h4>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-brand-ink/10 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                    <th class="pb-2 pr-4">{{ __('From') }}</th>
                                    <th class="pb-2 pr-4">{{ __('To') }}</th>
                                    <th class="pb-2">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/8 font-mono text-brand-ink">
                                @foreach ($redirects as $rule)
                                    <tr>
                                        <td class="py-2 pr-4 align-top break-all">{{ $rule['from'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 align-top break-all">{{ $rule['to'] ?? '—' }}</td>
                                        <td class="py-2 align-top">{{ $rule['status'] ?? 301 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($rewrites !== [])
                <div class="px-6 py-4 sm:px-8">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Rewrites') }}</h4>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-brand-ink/10 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                    <th class="pb-2 pr-4">{{ __('From') }}</th>
                                    <th class="pb-2">{{ __('To') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/8 font-mono text-brand-ink">
                                @foreach ($rewrites as $rule)
                                    <tr>
                                        <td class="py-2 pr-4 align-top break-all">{{ $rule['from'] ?? '—' }}</td>
                                        <td class="py-2 align-top break-all">{{ $rule['to'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($headers !== [])
                <div class="px-6 py-4 sm:px-8">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Header rules') }}</h4>
                    <div class="mt-3 space-y-4">
                        @foreach ($headers as $rule)
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 dark:bg-zinc-900/40">
                                <p class="font-mono text-xs text-brand-ink">{{ $rule['for'] ?? '—' }}</p>
                                @if (is_array($rule['values'] ?? null) && $rule['values'] !== [])
                                    <dl class="mt-2 space-y-1">
                                        @foreach ($rule['values'] as $name => $value)
                                            <div class="flex flex-wrap gap-x-2 font-mono text-[11px]">
                                                <dt class="text-brand-mist">{{ $name }}</dt>
                                                <dd class="min-w-0 break-all text-brand-ink">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</section>
