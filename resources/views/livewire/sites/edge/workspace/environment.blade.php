<div class="space-y-6">
    {{-- dply.yaml integration banner (same pattern as Crons / Firewall / Routing) --}}
    <section class="dply-card">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Env vars in dply.yaml') }}</h3>
                    <p class="mt-0.5 text-sm text-brand-moss">
                        {{ __('You can declare env vars in :file too. The format intentionally splits public vs secret so you don\'t leak credentials.', ['file' => $sourcePath]) }}
                    </p>
                </div>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                    {{ __('Generate dply.yaml') }}
                </a>
            </div>
        </div>

        {{-- Safety messaging --}}
        <div class="border-b border-brand-ink/10 bg-amber-50 px-6 py-3 text-xs text-amber-900 sm:px-8">
            <p class="font-semibold uppercase tracking-wide">{{ __('Is committing env vars safe?') }}</p>
            <p class="mt-1">
                {{ __('Only for non-secret values — `NEXT_PUBLIC_*`, `NODE_VERSION`, feature flags, etc. Anyone with read access to your repo (current and former contributors, CI, forks) sees what\'s in :file. For secrets, list the NAMES in `env.secret:` and set the actual values in the dashboard below.', ['file' => $sourcePath]) }}
            </p>
        </div>

        {{-- From dply.yaml --}}
        <div class="px-6 py-4 sm:px-8">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo-managed') }}</span>
            </div>
            @php
                $repoPublic = is_array($repoEnv['public'] ?? null) ? $repoEnv['public'] : [];
                $repoSecret = is_array($repoEnv['secret'] ?? null) ? $repoEnv['secret'] : [];
            @endphp
            @if ($repoPublic !== [] || $repoSecret !== [])
                <div class="mt-2 space-y-3">
                    @if ($repoPublic !== [])
                        <div class="rounded-lg border border-brand-ink/10 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Public (values committed to repo)') }}</p>
                            <table class="mt-2 min-w-full text-xs">
                                <tbody class="divide-y divide-brand-ink/8">
                                    @foreach ($repoPublic as $name => $value)
                                        <tr>
                                            <td class="py-1 pr-3 font-mono font-semibold text-brand-ink">{{ $name }}</td>
                                            <td class="py-1 font-mono text-brand-moss break-all">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($repoSecret !== [])
                        <div class="rounded-lg border border-brand-ink/10 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Secret (names only — values in dashboard)') }}</p>
                            <ul class="mt-2 space-y-1 font-mono text-xs">
                                @foreach ($repoSecret as $name)
                                    @php $isMissing = in_array($name, $missingSecrets, true); @endphp
                                    <li class="flex items-center gap-2">
                                        <span class="{{ $isMissing ? 'text-rose-700' : 'text-brand-ink' }}">{{ $name }}</span>
                                        @if ($isMissing)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-900">{{ __('Missing — set below') }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900">{{ __('Set in dashboard') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($missingSecrets !== [])
                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                            <p class="font-semibold">{{ __('Heads up') }}</p>
                            <p class="mt-1">{{ __(':count secret(s) declared in :file have no value set in the dashboard yet. Add them below before the next deploy.', ['count' => count($missingSecrets), 'file' => $sourcePath]) }}</p>
                        </div>
                    @endif
                </div>
            @else
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('No env vars declared in :file. Add an `env:` block if you want some IaC-style declarations:', ['file' => $sourcePath]) }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>env:
  # Safe to commit — anyone with repo access sees these.
  public:
    NODE_VERSION: "20"
    NEXT_PUBLIC_API: "https://api.example.com"
    FEATURE_NEW_NAV: "true"

  # NAMES ONLY — values stay in the dashboard.
  # dply warns at build time if any are missing a dashboard value.
  secret:
    - DATABASE_URL
    - STRIPE_SECRET_KEY</code></pre>
            @endif
        </div>
    </section>

    @include('livewire.sites.partials.edge.environment-settings')
</div>
