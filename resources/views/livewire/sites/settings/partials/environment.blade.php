@php
    use App\Models\ConsoleAction;
    use App\Services\Sites\DotEnvFileParser;

    $card = 'dply-card overflow-hidden';

    // Capability gates: only VM-style hosts have a server-side .env file we can
    // sync from / push to. Container/serverless runtimes still use the same
    // encrypted cache (since there's nothing on disk to read), so the rest of
    // the UX is identical — only the Sync/Push buttons disappear.
    $supportsEnvPush = $server->hostCapabilities()->supportsEnvPushToHost();

    // Parse the encrypted cache once per render. The Livewire methods that
    // mutate keys do their own parse/write round-trip on save.
    $parsed = app(DotEnvFileParser::class)->parse((string) ($site->env_file_content ?? ''));
    $envMap = $parsed['variables'];
    $envComments = $parsed['comments'] ?? [];
    $parserErrors = $parsed['errors'];
    ksort($envMap);

    // Workspace-inherited keys (read-only on this page; managed at the project
    // level). Used both for the inherited section and to suppress the
    // "Discovered from server" badge for keys that legitimately came from
    // workspace inheritance.
    $workspaceVariables = $site->workspace?->variables ?? collect();
    $inheritedKeys = $workspaceVariables->pluck('env_key')->map(fn ($k) => (string) $k)->all();

    $cacheOrigin = (string) ($site->env_cache_origin ?? '');
    $syncedAt = $site->env_synced_at;
    $editedAt = $site->updated_at;

    // Freshness pill copy: prefer the synced timestamp when the cache came
    // from a server read; otherwise show when the operator last edited.
    if ($cacheOrigin === 'server' && $syncedAt) {
        $freshnessLabel = __('synced :time', ['time' => $syncedAt->diffForHumans()]);
    } elseif ($cacheOrigin === 'local-edit' && $editedAt) {
        $freshnessLabel = __('edited :time', ['time' => $editedAt->diffForHumans()]);
    } else {
        $freshnessLabel = null;
    }

    $variableCount = count($envMap);

    // Sync-in-flight gates wire:poll on the keys list so the page picks up
    // the cache update the moment the lazy first-visit sync (or a manual
    // Sync) finishes. Site variables IS the live view — auto-sync hydrates
    // it on first visit and auto-push keeps it pushed; no separate live
    // panel exists anymore.
    $envSyncInFlight = $supportsEnvPush
        && ConsoleAction::query()
            ->forSubject($site)
            ->ofKind('env_sync')
            ->notDismissed()
            ->inFlight()
            ->exists();

    // Surface "env file lives inside the docroot" as an inline finding so
    // the operator can one-click move it. Same condition as the doctor
    // command's drift check — they should always agree.
    $envInDocroot = false;
    if ($supportsEnvPush) {
        $envPath = $site->effectiveEnvFilePath();
        $docroot = rtrim((string) $site->effectiveDocumentRoot(), '/');
        $envInDocroot = $docroot !== '' && str_starts_with($envPath, $docroot.'/');
    }
@endphp

<section
    class="space-y-6"
    @if ($supportsEnvPush && empty($envMap) && $cacheOrigin === '' && ! $envSyncInFlight)
        wire:init="autoSyncIfFirstVisit"
    @endif
>
    {{-- wire:init above lazy-fires the first-visit sync once after the page
         renders. The conditions ensure it only runs when truly necessary —
         empty cache, no recorded origin, no in-flight job. The sync banner
         shows progress at the top of the page; the keys list re-renders
         when the job completes (see wire:poll below). --}}
    {{-- Apply / sync banners are mounted at settings.blade.php top level; adding
         'env_sync' to config('console_actions.section_kinds.environment') makes
         the env-sync banner appear here automatically. --}}

    <x-explainer tone="info">
        <p>{{ __('Environment variables are written into the site\'s `.env` file on the server. Dply keeps an encrypted cache of the file so this page renders without an SSH round-trip.') }}</p>
        <p>{{ __('Workflow: paste a block or edit single keys — every change auto-pushes to the server, no manual save needed. Click Sync from server to pull drift caused by out-of-band edits.') }}</p>
        <p>{{ __('For runtimes without a server file (Docker, Kubernetes, Serverless), the cache IS the source of truth — the deploy job injects values when packaging the runtime.') }}</p>
        <p>
            <span class="font-semibold">{{ __('Browser exposure:') }}</span>
            {{ __('Dply\'s managed webserver config (Nginx, Apache, Caddy, OpenLiteSpeed) denies any HTTP request whose path starts with a dot — so /.env returns 403 even though the file lives in the docroot. /.well-known/ stays allowed for ACME challenges.') }}
            {{ __('For belt-and-suspenders defense, expand the Advanced disclosure below to relocate the file outside the docroot (e.g. /etc/dply/<slug>.env).') }}
        </p>
    </x-explainer>

    @if ($envInDocroot)
        <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-900">
            <div class="min-w-0">
                <p class="font-semibold">{{ __('Env file lives inside the docroot.') }}</p>
                <p class="mt-1">
                    {{ __(':path is reachable by the webserver. The default deny rule blocks /.env over HTTP, but moving the file outside the docroot is safer if the rule is ever changed or bypassed.', ['path' => $site->effectiveEnvFilePath()]) }}
                </p>
            </div>
            <button
                type="button"
                wire:click="relocateEnvOutsideDocroot"
                wire:loading.attr="disabled"
                wire:target="relocateEnvOutsideDocroot"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
                title="{{ __('Move .env to /etc/dply/:slug.env (root:site-user 640) and push.', ['slug' => $site->slug]) }}"
            >
                <x-heroicon-o-arrow-up-on-square class="h-3.5 w-3.5" />
                {{ __('Move outside docroot') }}
            </button>
        </div>
    @endif

    @if ($parserErrors !== [])
        <div class="rounded-xl border border-red-200 bg-red-50/80 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">{{ __('The cached .env has parse errors. Fix and push to clear:') }}</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($parserErrors as $err)
                    <li class="font-mono text-xs">{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Slim header card: icon, title, count + freshness, primary CTAs.
         Mirrors basic-auth's header so the two settings pages feel like a
         family. --}}
    <div class="{{ $card }}">
        <div class="flex flex-col gap-4 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
            <div class="flex min-w-0 items-start gap-3">
                <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                    <x-heroicon-o-key class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Environment variables') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Key/value pairs written into the site\'s .env file.') }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ trans_choice('{0} no variables|{1} :count variable|[2,*] :count variables', $variableCount, ['count' => $variableCount]) }}
                        </span>
                        @if ($workspaceVariables->isNotEmpty())
                            <span class="text-brand-mist/60">·</span>
                            <span class="inline-flex items-center gap-1">
                                <x-heroicon-m-link class="h-3 w-3" />
                                {{ trans_choice('{1} :count inherited|[2,*] :count inherited', $workspaceVariables->count(), ['count' => $workspaceVariables->count()]) }}
                            </span>
                        @endif
                        @if ($freshnessLabel)
                            <span class="text-brand-mist/60">·</span>
                            <span>{{ $freshnessLabel }}</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($supportsEnvPush)
                    {{-- Sync stays as the manual escape hatch for "someone edited
                         .env on the server out-of-band" — auto-sync on first visit
                         covers the empty-cache case, but drift recovery is still a
                         conscious operator action because it overwrites local edits. --}}
                    <button
                        type="button"
                        wire:click="syncEnvFromServer"
                        wire:loading.attr="disabled"
                        wire:target="syncEnvFromServer"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                        title="{{ __('Re-read the live .env from the server and replace the cached copy. Use when the server file has been edited outside Dply.') }}"
                    >
                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" wire:loading.remove wire:target="syncEnvFromServer" />
                        <span wire:loading wire:target="syncEnvFromServer" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        <span wire:loading.remove wire:target="syncEnvFromServer">{{ __('Sync from server') }}</span>
                        <span wire:loading wire:target="syncEnvFromServer">{{ __('Reading…') }}</span>
                    </button>
                @endif
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'add-env-modal')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add variable') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Add modal: single-row form on top, bulk-paste disclosure underneath.
         Mirrors basic-auth's add modal pattern. --}}
    <x-modal name="add-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Environment variable') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a variable') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Add a single KEY=value pair, or open the bulk import disclosure to paste a multi-line .env block.') }}
            </p>
            {{-- Top-right close. Mirrors the Cancel button at the bottom but
                 is always visible, so the operator can dismiss without
                 scrolling through a long bulk-paste block. --}}
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
                title="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <form wire:submit="addEnvVar" id="add-env-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-1">
                        <x-input-label for="new_env_key" :value="__('Key')" />
                        <x-text-input
                            id="new_env_key"
                            wire:model="new_env_key"
                            class="mt-1 block w-full font-mono text-sm"
                            autocomplete="off"
                            placeholder="APP_DEBUG"
                        />
                        <x-input-error :messages="$errors->get('new_env_key')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2"
                        x-data="{
                            showValue: false,
                            async copyValue() {
                                const v = document.getElementById('new_env_value')?.value || '';
                                if (!v) return;
                                try { await navigator.clipboard.writeText(v); } catch (e) {}
                            },
                        }"
                    >
                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="new_env_value">
                            <span>{{ __('Value') }}</span>
                            <span class="flex items-center gap-3 text-xs">
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="copyValue()">
                                    {{ __('Copy') }}
                                </button>
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                    <span x-show="!showValue">{{ __('Show') }}</span>
                                    <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                </button>
                            </span>
                        </label>
                        <input
                            id="new_env_value"
                            wire:model="new_env_value"
                            x-bind:type="showValue ? 'text' : 'password'"
                            autocomplete="off"
                            spellcheck="false"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                        />
                        <x-input-error :messages="$errors->get('new_env_value')" class="mt-1" />
                    </div>
                </div>
                {{-- Optional comment that renders as a `# ...` line above the
                     KEY=value in the .env file. Useful for "what is this for?"
                     reminders that survive into deploys. Multi-line comments
                     emit one `#` line each. --}}
                <div>
                    <x-input-label for="new_env_comment" :value="__('Comment (optional)')" />
                    <textarea
                        id="new_env_comment"
                        wire:model="new_env_comment"
                        rows="2"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        placeholder="{{ __('e.g. Stripe webhook signing secret — rotate quarterly') }}"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Rendered as a # comment line above this variable in the .env file.') }}
                    </p>
                    <x-input-error :messages="$errors->get('new_env_comment')" class="mt-1" />
                </div>
            </form>

            {{-- Bulk import: paste multi-line .env content. Same shape as basic-auth
                 bulk-import disclosure. Lines are merged into the existing cache —
                 keys not in the paste are preserved; pasted keys overwrite. --}}
            <details class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                        {{ __('Bulk import — paste a .env block') }}
                    </span>
                </summary>
                <form wire:submit="bulkImportEnvVars" class="mt-3 space-y-3">
                    <div>
                        <x-input-label for="bulk_env_input" :value="__('Lines (KEY=value — one per line)')" />
                        <textarea
                            id="bulk_env_input"
                            wire:model="bulk_env_input"
                            rows="8"
                            class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                            placeholder="# Database settings&#10;DB_PASSWORD=hunter2&#10;&#10;APP_NAME=&quot;My App&quot;&#10;export AWS_REGION=us-east-1"
                        ></textarea>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Existing keys not pasted are preserved. Pasted keys overwrite matching existing values. # comment lines directly above a KEY=value are kept as that variable\'s comment; free-floating comments and blank lines are dropped.') }}
                        </p>
                        <x-input-error :messages="$errors->get('bulk_env_input')" class="mt-1" />
                    </div>
                    <div class="flex justify-end">
                        <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="bulkImportEnvVars">
                            <span wire:loading.remove wire:target="bulkImportEnvVars">{{ __('Import variables') }}</span>
                            <span wire:loading wire:target="bulkImportEnvVars">{{ __('Importing…') }}</span>
                        </x-secondary-button>
                    </div>
                </form>
            </details>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">
                @if ($supportsEnvPush)
                    {{ __('Saved and auto-pushed to the server.') }}
                @else
                    {{ __('Saved. Values are injected on the next deploy.') }}
                @endif
            </p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-env-form" wire:loading.attr="disabled" wire:target="addEnvVar">
                <span wire:loading.remove wire:target="addEnvVar">{{ __('Add variable') }}</span>
                <span wire:loading wire:target="addEnvVar">{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- Workspace-inherited preview. Read-only here; managed at the project
         level. Placed above the per-key list so operators see what they can
         override before scanning the cache. --}}
    @if ($workspaceVariables->isNotEmpty())
        <details class="{{ $card }}">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-link class="h-4 w-4 text-brand-moss" />
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Inherited from project workspace') }}</span>
                    <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[11px] font-semibold text-brand-moss">
                        {{ trans_choice('{1} :count variable|[2,*] :count variables', $workspaceVariables->count(), ['count' => $workspaceVariables->count()]) }}
                    </span>
                </div>
                <span class="text-[11px] text-brand-mist">{{ __('Click to expand') }}</span>
            </summary>
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($workspaceVariables->sortBy('env_key') as $wsVar)
                    <li class="flex items-center justify-between gap-3 px-6 py-2.5 sm:px-8" wire:key="ws-var-{{ $wsVar->id }}">
                        <span class="font-mono text-sm text-brand-ink">{{ $wsVar->env_key }}</span>
                        <span class="text-[11px] text-brand-mist">
                            @if ((bool) ($wsVar->is_secret ?? false))
                                {{ __('Secret — managed in project settings') }}
                            @else
                                {{ __('Project-managed — override by adding the same key here') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- The per-key list. Each row: key (font-mono) + masked value with toggle,
         inline edit, trash. "Discovered from server" badge fires when the cache
         came from a sync (origin === 'server') and the key isn't part of the
         workspace inherited set. --}}
    <div
        class="{{ $card }}"
        @if ($envSyncInFlight) wire:poll.3s @endif
    >
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
            <div>
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Site variables') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">
                    @if ($supportsEnvPush)
                        {{ __('Edits push to the server automatically. Click Sync from server to pull drift caused by out-of-band edits.') }}
                    @else
                        {{ __('Edits are injected into the runtime on the next deploy.') }}
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($variableCount > 0)
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'view-all-env-modal')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        title="{{ __('Open a read-only textarea with the whole .env contents — handy for copy/paste.') }}"
                    >
                        <x-heroicon-o-document-text class="h-3.5 w-3.5" />
                        {{ __('View all') }}
                    </button>
                @endif
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2.5 py-1 text-[11px] font-semibold text-brand-moss">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                    {{ trans_choice('{0} no variables|{1} :count variable|[2,*] :count variables', $variableCount, ['count' => $variableCount]) }}
                </span>
            </div>
        </div>

        @if ($variableCount === 0)
            <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                    <x-heroicon-o-key class="h-6 w-6" />
                </span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No variables yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add a variable above, or click Sync from server to import from an existing .env.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($envMap as $key => $value)
                    @php
                        $isRevealed = in_array($key, $revealed_env_keys, true);
                        $isEditing = $editing_env_key === $key;
                        $isInherited = in_array($key, $inheritedKeys, true);
                        $showDiscoveredBadge = $cacheOrigin === 'server' && ! $isInherited;
                        $valueLength = strlen($value);
                        $rowComment = $envComments[$key] ?? null;
                    @endphp
                    <li class="px-6 py-3 sm:px-8" wire:key="env-row-{{ md5($key) }}">
                        @if ($isEditing)
                            {{-- Inline edit form. Cancel reverts; Save writes and closes. --}}
                            <form wire:submit="saveEditedEnvVar" class="space-y-3">
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="flex-1 min-w-[10rem]">
                                        <x-input-label for="editing_env_key_{{ md5($key) }}" :value="__('Key')" />
                                        <x-text-input
                                            id="editing_env_key_{{ md5($key) }}"
                                            wire:model="editing_env_key"
                                            class="mt-1 block w-full font-mono text-sm"
                                        />
                                        <x-input-error :messages="$errors->get('editing_env_key')" class="mt-1" />
                                    </div>
                                    <div class="flex-1 min-w-[12rem]" x-data="{ showValue: true }">
                                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="editing_env_value_{{ md5($key) }}">
                                            <span>{{ __('Value') }}</span>
                                            <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                                <span x-show="!showValue">{{ __('Show') }}</span>
                                                <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                            </button>
                                        </label>
                                        <input
                                            id="editing_env_value_{{ md5($key) }}"
                                            wire:model="editing_env_value"
                                            x-bind:type="showValue ? 'text' : 'password'"
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                                        />
                                        <x-input-error :messages="$errors->get('editing_env_value')" class="mt-1" />
                                    </div>
                                </div>
                                <div>
                                    <x-input-label for="editing_env_comment_{{ md5($key) }}" :value="__('Comment (optional)')" />
                                    <textarea
                                        id="editing_env_comment_{{ md5($key) }}"
                                        wire:model="editing_env_comment"
                                        rows="2"
                                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                        placeholder="{{ __('Renders as a # comment line above this variable in the .env file.') }}"
                                    ></textarea>
                                    <x-input-error :messages="$errors->get('editing_env_comment')" class="mt-1" />
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <x-secondary-button type="button" wire:click="cancelEditEnvVar">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedEnvVar">
                                        <span wire:loading.remove wire:target="saveEditedEnvVar">{{ __('Save') }}</span>
                                        <span wire:loading wire:target="saveEditedEnvVar">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/30 text-brand-forest sm:inline-flex">
                                        <x-heroicon-o-key class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 font-mono text-sm font-semibold text-brand-ink">
                                            <span>{{ $key }}</span>
                                            @if ($showDiscoveredBadge)
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-sky-800 ring-1 ring-inset ring-sky-200/70"
                                                    title="{{ __('Imported from the live .env on the server.') }}"
                                                >
                                                    <x-heroicon-m-magnifying-glass class="h-3 w-3" />
                                                    {{ __('Discovered') }}
                                                </span>
                                            @endif
                                            @if ($isInherited)
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900 ring-1 ring-inset ring-amber-200/70"
                                                    title="{{ __('This site key overrides a workspace-inherited variable.') }}"
                                                >
                                                    <x-heroicon-m-link class="h-3 w-3" />
                                                    {{ __('Override') }}
                                                </span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 break-all font-mono text-[11px] text-brand-moss">
                                            @if ($isRevealed)
                                                {{ $value === '' ? '(empty)' : $value }}
                                            @else
                                                @if ($valueLength === 0)
                                                    <span class="text-brand-mist">(empty)</span>
                                                @else
                                                    {{ str_repeat('•', min(24, max(4, $valueLength))) }}
                                                @endif
                                            @endif
                                        </p>
                                        @if ($rowComment !== null && $rowComment !== '')
                                            {{-- Comment shows in plain (not mono) so it visually
                                                 separates from the KEY/value mono pair. The pre-line
                                                 white-space preserves multi-line comments without
                                                 breaking the grid layout. --}}
                                            <p class="mt-1 whitespace-pre-line text-[11px] italic text-brand-mist">
                                                # {{ $rowComment }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="toggleRevealEnvVar('{{ $key }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        title="{{ $isRevealed ? __('Hide value') : __('Reveal value') }}"
                                    >
                                        @if ($isRevealed)
                                            <x-heroicon-o-eye-slash class="h-3.5 w-3.5" />
                                            {{ __('Hide') }}
                                        @else
                                            <x-heroicon-o-eye class="h-3.5 w-3.5" />
                                            {{ __('Show') }}
                                        @endif
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="editEnvVar('{{ $key }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        title="{{ __('Edit value') }}"
                                    >
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="confirmRemoveEnvVar('{{ $key }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="confirmRemoveEnvVar('{{ $key }}')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40"
                                        title="{{ __('Remove variable') }}"
                                        aria-label="{{ __('Remove') }}"
                                    >
                                        <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="confirmRemoveEnvVar('{{ $key }}')" />
                                        <span wire:loading wire:target="confirmRemoveEnvVar('{{ $key }}')"><x-spinner variant="forest" size="sm" /></span>
                                    </button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- "View all" modal: pre-rendered .env block in a read-only textarea
         for select-all + copy. Defaults to masked (KEY=••••) so a casual
         open doesn't leak values into the screen / scrollback; one click
         flips to cleartext. The unmasked text is the same blob the pusher
         would write to the server, so the operator can confirm format. --}}
    @if ($variableCount > 0)
        @php
            // Build a masked version (KEY=••••) and the cleartext version
            // server-side so neither has to be re-derived in JS. Both go
            // into Alpine state below; the textarea binds to whichever
            // mode is currently selected.
            $maskedLines = [];
            $cleartextLines = [];
            $sortedEnvMap = $envMap;
            ksort($sortedEnvMap);
            foreach ($sortedEnvMap as $k => $v) {
                $cleartextLines[] = $k.'='.(string) $v;
                $len = strlen((string) $v);
                $maskedLines[] = $k.'='.($len === 0 ? '' : str_repeat('•', min(24, max(4, $len))));
            }
            $cleartextBlob = implode("\n", $cleartextLines);
            $maskedBlob = implode("\n", $maskedLines);
        @endphp
        <x-modal name="view-all-env-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
            <div
                x-data="{
                    revealed: false,
                    copied: false,
                    masked: @js($maskedBlob),
                    cleartext: @js($cleartextBlob),
                    get text() { return this.revealed ? this.cleartext : this.masked; },
                    async copy() {
                        try { await navigator.clipboard.writeText(this.cleartext); this.copied = true; setTimeout(() => this.copied = false, 1800); } catch (e) {}
                    },
                }"
            >
                <div class="relative border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Site variables') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('All variables') }}</h2>
                    <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                        {{ __('Read-only view of the full .env contents. Values are masked until you click Show — the Copy button always copies the cleartext.') }}
                    </p>
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        aria-label="{{ __('Close') }}"
                        title="{{ __('Close') }}"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="px-6 py-5">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                        <span class="text-[11px] uppercase tracking-[0.16em] text-brand-mist">
                            {{ trans_choice('{1} :count variable|[2,*] :count variables', $variableCount, ['count' => $variableCount]) }}
                        </span>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" @click="revealed = !revealed" class="font-medium text-brand-sage hover:underline">
                                <span x-show="!revealed">{{ __('Show values') }}</span>
                                <span x-show="revealed" x-cloak>{{ __('Hide values') }}</span>
                            </button>
                            <button type="button" @click="copy()" class="font-medium text-brand-sage hover:underline">
                                <span x-show="!copied">{{ __('Copy all') }}</span>
                                <span x-show="copied" x-cloak class="text-emerald-700">{{ __('Copied') }}</span>
                            </button>
                        </div>
                    </div>
                    <textarea
                        readonly
                        rows="20"
                        class="w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        x-text="text"
                        @click="$event.target.select()"
                    ></textarea>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                    <p class="mr-auto text-xs text-brand-moss">{{ __('Use Bulk import in the Add modal to apply edits.') }}</p>
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- Advanced: relocate the .env file. Hidden behind a disclosure since
         most operators want the default (the docroot's .env, protected by
         the webserver deny rule we inject by default). Power users can move
         it outside the docroot — e.g. /etc/dply/<slug>.env — for an extra
         layer of defense in case the deny rule ever fails or is removed. --}}
    @if ($supportsEnvPush)
        <details class="{{ $card }}">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cog-6-tooth class="h-4 w-4 text-brand-moss" />
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Advanced — .env file location') }}</span>
                </div>
                <span class="font-mono text-[11px] text-brand-mist">{{ $site->effectiveEnvFilePath() }}</span>
            </summary>
            <div class="px-6 py-5 sm:px-8 space-y-3">
                <p class="text-sm text-brand-moss">
                    {{ __('By default the .env file lives at :default.', ['default' => rtrim($site->effectiveEnvDirectory(), '/').'/.env']) }}
                    {{ __('Override the path to relocate it outside the docroot — useful as defense in depth even with the webserver-level deny rule.') }}
                </p>
                <form wire:submit="saveEnvFilePath" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[18rem]">
                        <x-input-label for="env_file_path_override" :value="__('Absolute path on host (leave blank for default)')" />
                        <x-text-input
                            id="env_file_path_override"
                            wire:model="env_file_path_override"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="/etc/dply/{{ $site->slug }}.env"
                            autocomplete="off"
                            spellcheck="false"
                        />
                        <x-input-error :messages="$errors->get('env_file_path_override')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEnvFilePath">
                        <span wire:loading.remove wire:target="saveEnvFilePath">{{ __('Save path') }}</span>
                        <span wire:loading wire:target="saveEnvFilePath">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </form>
                <p class="text-[11px] text-brand-moss">
                    {{ __('Push will mkdir -p the parent directory and write the file there. Sync and Load fetch from this path. The webserver deny rule for /.env still applies for the default location.') }}
                </p>
            </div>
        </details>
    @endif

    {{-- Bindings — managed-resource attachments that auto-inject connection
         env vars (DATABASE_URL, REDIS_URL, etc.). v1 surfaces what the
         deployment contract sees today (mostly VM-derived) as a read-only
         list so operators can confirm what the deploy job will read from
         the resource graph. Attach / provision / detach UI lands in a
         follow-up alongside per-site binding records. --}}
    @php
        $siteBindings = app(\App\Services\Deploy\SiteResourceBindingResolver::class)->forSite($site);
        $bindingStatusBadge = [
            'configured' => 'bg-emerald-100 text-emerald-800',
            'pending' => 'bg-amber-100 text-amber-900',
        ];
        $bindingTypeLabels = [
            'database' => __('Database'),
            'redis' => __('Redis'),
            'queue' => __('Queue'),
            'storage' => __('Object storage'),
            'scheduler' => __('Scheduler'),
            'workers' => __('Workers'),
            'publication' => __('Publication'),
        ];
    @endphp
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8 sm:py-6">
            <div class="flex items-start gap-3">
                <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                    <x-heroicon-o-link class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Bindings') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('Managed resources attached to this app. Each attachment auto-injects its connection variables (e.g. DATABASE_URL) so plain Variables above don\'t have to duplicate them.') }}
                    </p>
                </div>
            </div>
        </div>

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($siteBindings as $binding)
                <li class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">
                            {{ $bindingTypeLabels[$binding->type] ?? str($binding->type)->replace('_', ' ')->title() }}
                            @if ($binding->required)
                                <span class="ml-2 inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Required') }}</span>
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-brand-moss">
                            @if ($binding->name)
                                <span class="font-mono">{{ $binding->name }}</span> · {{ __('source:') }} <span class="font-mono">{{ $binding->source }}</span>
                            @else
                                {{ __('Not attached — derived from') }} <span class="font-mono">{{ $binding->source }}</span>
                            @endif
                        </p>
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.14em] {{ $bindingStatusBadge[$binding->status] ?? 'bg-slate-100 text-slate-700' }}">
                        {{ $binding->status }}
                    </span>
                </li>
            @endforeach
        </ul>

        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8 text-xs text-brand-moss">
            {{ __('Attach / provision new / detach actions land in a follow-up. For now, set connection strings as plain Variables above when a binding shows "pending".') }}
        </div>
    </section>

    <x-cli-snippet
        :intro="__('Manage env via CLI when you have many keys at once:')"
        :commands="[
            ['label' => __('Set one'), 'command' => 'dply:site:env-set '.$site->slug.' KEY=value'],
            ['label' => __('Bulk import from .env'), 'command' => 'dply:site:env-import '.$site->slug.' --file=.env'],
            ['label' => __('Export current as .env'), 'command' => 'dply:site:env-export '.$site->slug.' --to=.env'],
            ['label' => __('Diff cache vs server'), 'command' => 'dply:site:env-diff '.$site->slug],
        ]"
    />
</section>
