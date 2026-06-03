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

    // Required env vars detected from the deployed code by the scanner
    // (.env.example + env() usage in app code and config/). "Missing" = a
    // required key that isn't set with a non-empty value here and isn't
    // workspace-inherited. Only VM hosts have code on disk to scan.
    $envPresentKeys = [];
    foreach ($envMap as $envK => $envV) {
        if (trim((string) $envV) !== '') {
            $envPresentKeys[] = (string) $envK;
        }
    }
    $missingEnv = $supportsEnvPush ? $site->missingRequiredEnvKeys($envPresentKeys, $inheritedKeys) : [];
    $envRequirements = $site->envRequirements();
    $envScannedAt = $envRequirements['scanned_at'] ?? null;
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
        <div class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 bg-amber-50 px-5 py-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Exposure') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-amber-950">{{ __('Env file lives inside the docroot') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-amber-900">
                            {{ __(':path is reachable by the webserver. The default deny rule blocks /.env over HTTP, but moving the file outside the docroot is safer if the rule is ever changed or bypassed.', ['path' => $site->effectiveEnvFilePath()]) }}
                        </p>
                    </div>
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
        </div>
    @endif

    @if ($parserErrors !== [])
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-rose-50 px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Parse error') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-rose-900">{{ __('The cached .env has parse errors') }}</h3>
                    <ul class="mt-1 list-inside list-disc text-sm text-rose-800">
                        @foreach ($parserErrors as $err)
                            <li class="font-mono text-xs">{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- Missing required env warning. Driven by the scanner's detected
         requirements (refreshed each deploy; re-scan on demand). Lists the
         keys the deployed code expects but that aren't set here, with a
         one-click modal to add them. --}}
    @if ($supportsEnvPush && $missingEnv !== [])
        <div class="dply-card overflow-hidden">
            <div class="flex flex-col gap-3 bg-rose-50 px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Missing variables') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-rose-900">
                                {{ trans_choice('{1} :count required variable is missing|[2,*] :count required variables are missing', count($missingEnv), ['count' => count($missingEnv)]) }}
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-rose-900">
                                {{ __('These are referenced by the deployed code (.env.example, plus env() usage in app code and config/) but aren\'t set here. The app may error until they have values.') }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach (array_slice($missingEnv, 0, 24) as $entry)
                                    <span
                                        class="inline-flex items-center rounded-full bg-white px-2 py-0.5 font-mono text-[11px] font-semibold text-rose-800 ring-1 ring-inset ring-rose-200"
                                        title="{{ __('source: :s', ['s' => implode(', ', $entry['sources'])]) }}"
                                    >{{ $entry['key'] }}</span>
                                @endforeach
                                @if (count($missingEnv) > 24)
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                                        {{ __('+:count more', ['count' => count($missingEnv) - 24]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="rescanEnvRequirements"
                            wire:loading.attr="disabled"
                            wire:target="rescanEnvRequirements"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                            title="{{ __('Re-scan the deployed code for required variables.') }}"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="rescanEnvRequirements" />
                            <span wire:loading wire:target="rescanEnvRequirements" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                            {{ __('Re-scan') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openMissingEnvModal"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-rose-800"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add missing variables') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Slim header card: icon, title, count + freshness, primary CTAs.
         Mirrors basic-auth's header so the two settings pages feel like a
         family. --}}
    <div class="{{ $card }}">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Configuration') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Environment variables') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
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
                    x-on:click="$dispatch('open-modal', 'paste-env-modal')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    title="{{ __('Paste a multi-line .env block to import many variables at once.') }}"
                >
                    <x-heroicon-o-document-text class="h-3.5 w-3.5" />
                    {{ __('Paste .env') }}
                </button>
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
                {{ __('Add a single KEY=value pair. To import many at once, use the Paste .env button instead.') }}
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

    {{-- Paste .env: first-class bulk import. Paste a whole .env block and it
         merges into the existing cache — keys not in the paste are preserved,
         pasted keys overwrite. Closes on success (bulkImportEnvVars dispatches
         close-modal) so the operator drops back to the updated list. --}}
    <x-modal name="paste-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Environment') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Paste a .env') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Paste a multi-line .env block — one KEY=value per line. Existing keys you don\'t paste are preserved; pasted keys overwrite matching values.') }}
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

        <div class="px-6 py-6">
            <form wire:submit="bulkImportEnvVars" id="paste-env-form" class="space-y-3">
                <div>
                    <x-input-label for="paste_env_input" :value="__('.env contents')" />
                    <textarea
                        id="paste_env_input"
                        wire:model="bulk_env_input"
                        rows="14"
                        autocomplete="off"
                        spellcheck="false"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        placeholder="# Database settings&#10;DB_PASSWORD=hunter2&#10;&#10;APP_NAME=&quot;My App&quot;&#10;export AWS_REGION=us-east-1"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('# comment lines directly above a KEY=value are kept as that variable\'s comment; free-floating comments and blank lines are dropped.') }}
                    </p>
                    <x-input-error :messages="$errors->get('bulk_env_input')" class="mt-1" />
                </div>
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">
                @if ($supportsEnvPush)
                    {{ __('Imported keys auto-push to the server.') }}
                @else
                    {{ __('Imported keys are injected on the next deploy.') }}
                @endif
            </p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="paste-env-form" wire:loading.attr="disabled" wire:target="bulkImportEnvVars">
                <span wire:loading.remove wire:target="bulkImportEnvVars">{{ __('Import variables') }}</span>
                <span wire:loading wire:target="bulkImportEnvVars">{{ __('Importing…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- "Add missing variables" modal: one input per still-missing required
         key, pre-seeded by openMissingEnvModal() with the .env.example sample
         value. Blank inputs are skipped on submit (addMissingEnvVars). --}}
    <x-modal name="add-missing-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-700">{{ __('Missing variables') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add the required variables') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Detected from the deployed code but not set on this site. Fill in the ones you have — blanks are skipped. Saved to the Environment section and auto-pushed to the server.') }}
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

        <div class="max-h-[60vh] overflow-y-auto px-6 py-6">
            <form wire:submit="addMissingEnvVars" id="add-missing-env-form" class="space-y-3">
                @forelse ($missingEnv as $entry)
                    <div wire:key="missing-env-{{ md5($entry['key']) }}">
                        <label class="block font-mono text-xs font-semibold text-brand-ink" for="missing_env_{{ md5($entry['key']) }}">{{ $entry['key'] }}</label>
                        <input
                            id="missing_env_{{ md5($entry['key']) }}"
                            wire:model="missing_env_values.{{ $entry['key'] }}"
                            autocomplete="off"
                            spellcheck="false"
                            class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                            placeholder="{{ $entry['example'] !== null && $entry['example'] !== '' ? $entry['example'] : __('value') }}"
                        />
                        <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('source: :s', ['s' => implode(', ', $entry['sources'])]) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-brand-moss">{{ __('Nothing missing — all required variables are set.') }}</p>
                @endforelse
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Saved and auto-pushed to the server.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-missing-env-form" wire:loading.attr="disabled" wire:target="addMissingEnvVars">
                <span wire:loading.remove wire:target="addMissingEnvVars">{{ __('Add variables') }}</span>
                <span wire:loading wire:target="addMissingEnvVars">{{ __('Adding…') }}</span>
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
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Variables') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site variables') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        @if ($supportsEnvPush)
                            {{ __('Edits push to the server automatically. Click Sync from server to pull drift caused by out-of-band edits.') }}
                        @else
                            {{ __('Edits are injected into the runtime on the next deploy.') }}
                        @endif
                    </p>
                </div>
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
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
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
                    <p class="mr-auto text-xs text-brand-moss">{{ __('Use Paste .env to apply edits in bulk.') }}</p>
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
        <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8 sm:py-6">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                    <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Resources') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Bindings') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Managed resources attached to this app. Each attachment auto-injects its connection variables (e.g. DATABASE_URL) so plain Variables above don\'t have to duplicate them.') }}
                    </p>
                </div>
            </div>
        </div>

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($siteBindings as $binding)
                <li class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
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
                        @if (! empty($binding->config['last_error']))
                            <p class="mt-1 text-xs text-rose-700">{{ $binding->config['last_error'] }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.14em] {{ $bindingStatusBadge[$binding->status] ?? 'bg-brand-sand/40 text-brand-moss' }}">
                            {{ $binding->status }}
                        </span>
                        @if ($binding->bindingId)
                            <button type="button" wire:click="detachBinding('{{ $binding->bindingId }}')" wire:confirm="{{ __('Detach this :type binding? Its connection variables stop being injected at deploy.', ['type' => $binding->type]) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                {{ __('Detach') }}
                            </button>
                        @elseif ($binding->manageable)
                            @if ($binding->type === 'database')
                                <button type="button" wire:click="openBindingModal('database', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-link class="h-3.5 w-3.5" />
                                    {{ __('Attach existing') }}
                                </button>
                                <button type="button" wire:click="openBindingModal('database', 'provision')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Provision new') }}
                                </button>
                            @else
                                <button type="button" wire:click="openBindingModal('{{ $binding->type }}', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-link class="h-3.5 w-3.5" />
                                    {{ __('Configure') }}
                                </button>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8 text-xs text-brand-moss">
            {{ __('Attaching a resource injects its connection variables (e.g. DATABASE_URL) at deploy time only — they stay out of the editable Variables list above. Publication is managed by the runtime and can\'t be detached here.') }}
        </div>
    </section>

    {{-- Shared attach / provision modal. Body switches on the chosen type +
         mode; form values live in the loose $bindingForm array on the
         component (see ManagesSiteBindings). --}}
    <x-modal name="site-binding-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        @php $bindingModalLabel = $bindingTypeLabels[$bindingModalType] ?? str($bindingModalType)->replace('_', ' ')->title(); @endphp
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $bindingModalMode === 'provision' ? __('Provision new') : __('Attach existing') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $bindingModalLabel ?: __('Binding') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-4 px-6 py-6">
            @if ($bindingModalType === 'database' && $bindingModalMode === 'attach')
                <div>
                    <x-input-label for="binding_db_target" :value="__('Server database')" />
                    <select id="binding_db_target" wire:model="bindingForm.target_id" class="dply-input">
                        <option value="">{{ __('Choose a database…') }}</option>
                        @foreach ($bindingTargets as $target)
                            <option value="{{ $target['id'] }}">{{ $target['label'] }}</option>
                        @endforeach
                    </select>
                    @if ($bindingTargets === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No databases on this server yet. Use Provision new, or create one in the server Databases workspace.') }}</p>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Injects DATABASE_URL and DB_* connection variables at deploy.') }}</p>
                    @endif
                </div>
            @elseif ($bindingModalType === 'database' && $bindingModalMode === 'provision')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="binding_db_engine" :value="__('Engine')" />
                        <select id="binding_db_engine" wire:model="bindingForm.engine" class="dply-input">
                            <option value="mysql">{{ __('MySQL / MariaDB') }}</option>
                            <option value="postgres">{{ __('PostgreSQL') }}</option>
                            <option value="sqlite">{{ __('SQLite') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="binding_db_name" :value="__('Database name')" />
                        <x-text-input id="binding_db_name" wire:model="bindingForm.name" class="mt-1 block w-full font-mono text-sm" placeholder="app_production" />
                    </div>
                </div>
                <p class="text-xs text-brand-moss">{{ __('Creates the database on this site\'s server with generated credentials and injects the connection variables.') }}</p>
            @elseif ($bindingModalType === 'queue')
                <div>
                    <x-input-label for="binding_queue_driver" :value="__('Queue driver')" />
                    <select id="binding_queue_driver" wire:model="bindingForm.driver" class="dply-input">
                        <option value="database">{{ __('Database') }}</option>
                        <option value="redis">{{ __('Redis') }}</option>
                    </select>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Sets QUEUE_CONNECTION. Redis requires the Redis binding to be attached too.') }}</p>
                </div>
            @elseif ($bindingModalType === 'storage')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="binding_storage_bucket" :value="__('Bucket')" />
                        <x-text-input id="binding_storage_bucket" wire:model="bindingForm.bucket" class="mt-1 block w-full font-mono text-sm" placeholder="my-app-assets" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_key" :value="__('Access key ID')" />
                        <x-text-input id="binding_storage_key" wire:model="bindingForm.access_key_id" class="mt-1 block w-full font-mono text-sm" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_secret" :value="__('Secret access key')" />
                        <x-text-input id="binding_storage_secret" type="password" wire:model="bindingForm.secret_access_key" class="mt-1 block w-full font-mono text-sm" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_region" :value="__('Region (optional)')" />
                        <x-text-input id="binding_storage_region" wire:model="bindingForm.region" class="mt-1 block w-full font-mono text-sm" placeholder="us-east-1" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_endpoint" :value="__('Endpoint (optional)')" />
                        <x-text-input id="binding_storage_endpoint" wire:model="bindingForm.endpoint" class="mt-1 block w-full font-mono text-sm" placeholder="https://nyc3.digitaloceanspaces.com" />
                    </div>
                </div>
                <p class="text-xs text-brand-moss">{{ __('Injects FILESYSTEM_DISK=s3 and the AWS_* connection variables at deploy.') }}</p>
            @elseif ($bindingModalType === 'redis')
                <p class="text-sm text-brand-moss">{{ __('Attaches this site to the server\'s Redis service and injects REDIS_HOST / REDIS_PORT / REDIS_CLIENT at deploy.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('Records this binding so deploy preflight treats it as configured.') }}</p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="button" wire:click="saveBinding" wire:loading.attr="disabled" wire:target="saveBinding">
                <span wire:loading.remove wire:target="saveBinding">{{ $bindingModalMode === 'provision' ? __('Provision') : __('Attach') }}</span>
                <span wire:loading wire:target="saveBinding">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

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
