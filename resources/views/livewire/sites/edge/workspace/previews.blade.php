<div class="space-y-6">
    @unless ($edgeIsPreviewChild)
        {{-- dply.yaml integration banner (same pattern as Crons / Firewall / Routing / Domains) --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Previews') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Preview policy') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Control which PRs and branches get auto-deployed as previews. Declare in :file under `previews:` to commit the policy to your repo.', ['file' => $sourcePath]) }}
                    </p>
                </div>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="ml-auto inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                    {{ __('Generate dply.yaml') }}
                </a>
            </div>

            {{-- From dply.yaml --}}
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-baseline justify-between gap-2">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo-managed') }}</span>
                </div>
                @if ($repoPreviews !== [])
                    <dl class="mt-2 grid grid-cols-1 gap-y-2 rounded-lg border border-brand-ink/10 p-3 text-xs sm:grid-cols-[10rem_1fr]">
                        @if (isset($repoPreviews['enabled']))
                            <dt class="text-brand-mist">{{ __('Enabled') }}</dt>
                            <dd class="text-brand-ink">{{ $repoPreviews['enabled'] ? __('yes') : __('no — PR webhooks skipped') }}</dd>
                        @endif
                        @if (isset($repoPreviews['pr_only']))
                            <dt class="text-brand-mist">{{ __('PR-only') }}</dt>
                            <dd class="text-brand-ink">{{ $repoPreviews['pr_only'] ? __('yes (default)') : __('no — also auto-preview pushes to listed branches') }}</dd>
                        @endif
                        @if (! empty($repoPreviews['branches']))
                            <dt class="text-brand-mist">{{ __('Extra branches') }}</dt>
                            <dd class="font-mono text-brand-ink">{{ implode(', ', $repoPreviews['branches']) }}</dd>
                        @endif
                        @if (! empty($repoPreviews['exclude_branches']))
                            <dt class="text-brand-mist">{{ __('Excluded') }}</dt>
                            <dd class="font-mono text-brand-ink">{{ implode(', ', $repoPreviews['exclude_branches']) }}</dd>
                        @endif
                        @if (! empty($repoPreviews['protection']['mode']))
                            <dt class="text-brand-mist">{{ __('Protection') }}</dt>
                            <dd class="text-brand-ink">
                                <span class="font-mono">{{ $repoPreviews['protection']['mode'] }}</span>
                                @if (! empty($repoPreviews['protection']['allowed_emails']))
                                    — <span class="text-brand-moss">{{ count($repoPreviews['protection']['allowed_emails']) }} {{ __('allowed emails') }}</span>
                                @endif
                            </dd>
                        @endif
                    </dl>
                @else
                    <p class="mt-2 text-sm text-brand-moss">
                        {{ __('No policy declared in :file. Default: every PR opened/reopened/synced gets an unprotected preview. Add a `previews:` block to gate this.', ['file' => $sourcePath]) }}
                    </p>
                    <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>previews:
  enabled: true               # global on/off
  pr_only: true               # PR webhooks only; ignore branch pushes
  exclude_branches:           # never preview these
    - "production"
  # branches:                 # opt-in extra branches (when pr_only: false)
  #   - "staging"
  protection:                 # auto-applied to every new preview
    mode: password            # none | password | dply-account | email
    # password set via dashboard; never committed
    # allowed_emails:         # only when mode: email
    #   - "designer@acme.com"</code></pre>
                @endif
            </div>

            {{-- Effective summary --}}
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Effective policy') }}</h4>
                <dl class="mt-2 grid grid-cols-1 gap-y-2 text-xs sm:grid-cols-[10rem_1fr]">
                    <dt class="text-brand-mist">{{ __('Auto-deploy previews') }}</dt>
                    <dd>
                        @if ($previewPolicy['enabled'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-emerald-900">{{ __('Enabled') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-rose-900">{{ __('Disabled') }}</span>
                        @endif
                    </dd>
                    <dt class="text-brand-mist">{{ __('Mode') }}</dt>
                    <dd class="text-brand-ink">
                        @if ($previewPolicy['pr_only'])
                            {{ __('PR-only (push events ignored)') }}
                        @else
                            {{ __('PR + listed branches') }}
                        @endif
                    </dd>
                    @if ($previewPolicy['exclude_branches'] !== [])
                        <dt class="text-brand-mist">{{ __('Excluded') }}</dt>
                        <dd class="font-mono text-brand-ink">{{ implode(', ', $previewPolicy['exclude_branches']) }}</dd>
                    @endif
                </dl>
            </div>

            {{-- Comment widget (dply.yaml-driven) --}}
            <div class="px-6 py-4 sm:px-8">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Comment widget') }}</h4>
                @if (isset($repoCommentWidget['enabled']))
                    <p class="mt-2 text-xs text-brand-ink">
                        @if ($repoCommentWidget['enabled'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-emerald-900">{{ __('Enabled') }}</span>
                            <span class="ml-1 text-brand-moss">{{ __('— injected into every preview deploy by repo policy. Dashboard toggle below acts as an override.') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Disabled') }}</span>
                            <span class="ml-1 text-brand-moss">{{ __('— previews ship without the widget. The dashboard toggle below has no effect when repo says disabled.') }}</span>
                        @endif
                    </p>
                @else
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Not declared in :file. Add to control via repo:', ['file' => $sourcePath]) }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>comment_widget:
  enabled: true</code></pre>
                @endif
            </div>
        </section>
    @endunless

    @include('livewire.sites.partials.edge.previews', [
        'latestReplays' => $latestReplays ?? collect(),
        'deployReplayEnabled' => $deployReplayEnabled ?? false,
    ])
    @unless ($edgeIsPreviewChild)
        @include('livewire.sites.partials.edge.preview-settings')
    @endunless
    @include('livewire.partials.confirm-action-modal')
</div>
