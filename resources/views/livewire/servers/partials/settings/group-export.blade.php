@php
    $noteCount = $this->serverNoteCounts['all'];
    $siteCount = $this->server->sites()->count();

    $strip = 'border-b border-brand-ink/10 px-5 py-4 sm:px-6';
    $contentsItem = 'flex items-start gap-2 text-sm text-brand-ink';
@endphp

<section id="settings-group-export" aria-labelledby="settings-group-export-title">
    <div class="{{ $card }} scroll-mt-24">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-arrow-down-tray"
            :title="__('Export')"
            :note="__('Take this server\'s context with you — for a handover doc, an audit, or your own records.')"
            title-id="settings-group-export-title"
            class="border-b border-brand-ink/10"
        />

        {{-- What an export is, and just as importantly what it is not. This tab
             used to be a lone Download button; operators reasonably read that as
             "back up my server", which it emphatically is not. --}}
        <div class="{{ $strip }} bg-brand-sand/15">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-brand-sage ring-1 ring-brand-ink/10" aria-hidden="true">
                    <x-heroicon-o-information-circle class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('These are reference documents, not backups.') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('An export describes how this server is configured and what you have written about it. It cannot be imported to rebuild the server, and it holds nothing that could be used to connect to it. For real recovery points use') }}
                        <a href="{{ route('servers.backups', ['server' => $server]) }}" wire:navigate class="font-medium text-brand-sage underline underline-offset-2 hover:text-brand-forest">{{ __('Backups') }}</a>{{ __(', and for a point-in-time copy of the machine use') }}
                        <a href="{{ route('servers.snapshots', ['server' => $server]) }}" wire:navigate class="font-medium text-brand-sage underline underline-offset-2 hover:text-brand-forest">{{ __('Snapshots') }}</a>.
                    </p>
                </div>
            </div>
        </div>

        {{-- Server manifest --}}
        <div class="{{ $strip }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                        <x-heroicon-o-document-text class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                        {{ __('Server manifest') }}
                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-medium text-brand-moss">JSON</span>
                    </h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('One file describing this server: how it is identified and reached, which sites live on it, and everything in your notebook. The usual reason to pull one is handing this server to someone else, attaching it to a ticket, or keeping a record before a rebuild.') }}
                    </p>
                </div>

                <x-secondary-button type="button" size="xs" wire:click="downloadServerManifest" wire:loading.attr="disabled" wire:target="downloadServerManifest" class="shrink-0">
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Download manifest') }}
                </x-secondary-button>
            </div>

            {{-- What's actually in the file, at the level of "which fields". --}}
            <div class="mt-4 grid gap-4 rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Server') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-ink">
                        {{ __('Name, status and health, provider and region, IP address, SSH user and port, plus your own labels — role, tags, OS, timezone, maintenance window and cost note.') }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">
                        {{ trans_choice(':count site|:count sites', $siteCount, ['count' => $siteCount]) }}
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-ink">
                        {{ $siteCount > 0
                            ? __('Each site on this server with the domains pointed at it.')
                            : __('Nothing yet — sites appear here once this server hosts one.') }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">
                        {{ trans_choice(':count note|:count notes', $noteCount, ['count' => $noteCount]) }}
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-ink">
                        {{ $noteCount > 0
                            ? __('Full Markdown body, tags, pinned and archived state, and who wrote or last edited each one.')
                            : __('Nothing yet — anything you write in Notes rides along with the manifest.') }}
                    </p>
                </div>
            </div>

            {{-- Long example under a disclosure so the strip stays slim. --}}
            <details class="group mt-3">
                <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-xs font-semibold text-brand-sage transition hover:text-brand-forest">
                    <x-heroicon-o-chevron-right class="h-3.5 w-3.5 transition group-open:rotate-90" aria-hidden="true" />
                    {{ __('See the shape of the file') }}
                </summary>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-ink px-3 py-2.5 text-xs leading-relaxed text-brand-sand"><code>{
  "exported_at": "{{ now()->toIso8601String() }}",
  "app": "{{ config('app.name') }}",
  "server": {
    "name": {{ json_encode($server->name) }},
    "status": {{ json_encode($server->status) }},
    "provider": {{ json_encode($server->provider?->value) }},
    "region": {{ json_encode($server->region) }},
    "ip_address": {{ json_encode($server->ip_address) }},
    "ssh_user": {{ json_encode($server->ssh_user) }},
    "ssh_port": {{ json_encode($server->ssh_port) }},
    "meta": { "server_role": "…", "tags": ["…"], "timezone": "…" }
  },
  "sites": [ { "name": "example.com", "domains": ["example.com"] } ],
  "notes": [
    {
      "body": "## Restart runbook\n…",
      "tags": ["runbook"],
      "pinned": true,
      "archived": false,
      "created_by": "…",
      "created_at": "…"
    }
  ]
}</code></pre>
            </details>

            {{-- Exclusions, stated plainly rather than implied by "secrets are
                 never included". --}}
            <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                <span class="inline-flex items-center gap-1 font-semibold">
                    <x-heroicon-o-lock-closed class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Never included:') }}
                </span>
                @foreach ([
                    __('SSH keys'),
                    __('environment variables'),
                    __('database credentials'),
                    __('deploy keys'),
                    __('certificates'),
                    __('provider API tokens'),
                ] as $excluded)
                    <span class="rounded-full bg-white px-2 py-0.5 ring-1 ring-brand-ink/10">{{ $excluded }}</span>
                @endforeach
            </div>
        </div>

        {{-- Notes on their own --}}
        <div class="{{ $strip }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                        <x-heroicon-o-book-open class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                        {{ __('Notebook') }}
                    </h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        @if ($noteCount > 0)
                            {{ trans_choice(
                                'Your :count note without the server metadata around it. Markdown reads as a document you can paste into a wiki or handover; JSON keeps the tags, archive state and comment threads for scripting.|Your :count notes without the server metadata around it. Markdown reads as a document you can paste into a wiki or handover; JSON keeps the tags, archive state and comment threads for scripting.',
                                $noteCount,
                                ['count' => $noteCount],
                            ) }}
                        @else
                            {{ __('Nothing to export yet. Write runbooks, customer IDs and handover context in the Notes tab and they become a downloadable document here.') }}
                        @endif
                    </p>
                    @if ($noteCount > 0)
                        <p class="mt-1.5 text-xs text-brand-moss/80">
                            {{ __('Includes archived notes. To download a narrower slice, filter the list on the Notes tab and export from there.') }}
                        </p>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @if ($noteCount > 0)
                        <x-secondary-button type="button" size="xs" wire:click="exportServerNotesMarkdown(true)" wire:loading.attr="disabled" wire:target="exportServerNotesMarkdown">
                            <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Markdown') }}
                        </x-secondary-button>
                        <x-secondary-button type="button" size="xs" wire:click="exportServerNotesJson(true)" wire:loading.attr="disabled" wire:target="exportServerNotesJson">
                            <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('JSON') }}
                        </x-secondary-button>
                    @else
                        <x-secondary-button
                            size="xs"
                            :href="route('servers.settings', ['server' => $server, 'tab' => 'notes'])"
                            wire:navigate
                        >
                            {{ __('Go to Notes') }}
                        </x-secondary-button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Transfer, still unavailable — say so where someone would look for it. --}}
        <div class="px-5 py-4 sm:px-6">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sand/40 text-brand-moss" aria-hidden="true">
                    <x-heroicon-o-arrows-right-left class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-brand-ink">
                        {{ __('Transfer to another account') }}
                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Coming soon') }}</span>
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Moving a server between dply accounts is not available yet. For now, export the manifest, add the new owner to this organization, or re-add the server from the destination account.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
