@php
    $card = 'dply-card overflow-hidden';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900';
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900';

    $kinds = [
        ['id' => 'kv', 'label' => __('KV namespaces'), 'icon' => 'heroicon-o-circle-stack'],
        ['id' => 'r2', 'label' => __('R2 buckets'),    'icon' => 'heroicon-o-archive-box'],
        ['id' => 'd1', 'label' => __('D1 databases'),  'icon' => 'heroicon-o-table-cells'],
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('edge.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Edge sites') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Bindings') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Bindings')"
                :description="__('Wire Cloudflare resources to your Edge site. Create or attach KV namespaces, R2 buckets, and D1 databases — then paste the generated snippet into dply.yaml. We never write to your repository.')"
                doc-route="docs.index"
                flush
                compact
            />

            @if (! $contextResolved)
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-100">
                    <p class="font-semibold">{{ __('Edge delivery is not configured yet') }}</p>
                    <p class="mt-1">
                        @if ($listError)
                            {{ $listError }}
                        @else
                            {{ __('Bindings require an active Cloudflare backend. Finish Edge setup (managed or BYO) before creating resources.') }}
                        @endif
                    </p>
                </section>
            @else
                @if ($listError)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-100">
                        {{ $listError }}
                    </div>
                @endif

                {{-- Kind tabs --}}
                <div class="dply-card overflow-hidden">
                    <nav class="flex flex-wrap gap-1 border-b border-brand-ink/10 px-3 py-2" aria-label="{{ __('Binding kinds') }}">
                        @foreach ($kinds as $entry)
                            <button
                                type="button"
                                wire:click="setKind('{{ $entry['id'] }}')"
                                @class([
                                    'inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                    'bg-brand-ink text-white shadow-sm' => $kind === $entry['id'],
                                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $kind !== $entry['id'],
                                ])
                                aria-pressed="{{ $kind === $entry['id'] ? 'true' : 'false' }}"
                            >
                                <x-dynamic-component :component="$entry['icon']" class="h-4 w-4" />
                                {{ $entry['label'] }}
                            </button>
                        @endforeach
                    </nav>
                </div>

                {{-- Snippet panel --}}
                @if ($snippetYaml !== null && $lastSnippet !== null)
                    <section class="dply-card overflow-hidden border-brand-forest/30 ring-1 ring-brand-forest/20" x-data="{ copied: false }">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 px-5 py-3">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-forest">{{ __('Ready to paste') }}</p>
                                <p class="mt-0.5 text-sm font-semibold text-brand-ink">
                                    {{ __('Add this to dply.yaml, commit, and redeploy') }}
                                </p>
                                <p class="mt-0.5 text-xs text-brand-moss">
                                    {{ __('Binding name :name → :kind :ref', [
                                        'name' => $lastSnippet['binding'] ?? '',
                                        'kind' => strtoupper((string) ($lastSnippet['kind'] ?? '')),
                                        'ref' => (string) ($lastSnippet['id'] ?? $lastSnippet['name'] ?? ''),
                                    ]) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="{{ $btnSecondary }}"
                                    @click="navigator.clipboard.writeText(@js($snippetYaml)); copied = true; setTimeout(() => copied = false, 2000)"
                                >
                                    <x-heroicon-o-clipboard class="h-4 w-4" />
                                    <span x-show="!copied">{{ __('Copy snippet') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                                <button type="button" wire:click="dismissSnippet" class="rounded-md p-1.5 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('Dismiss') }}">
                                    <x-heroicon-o-x-mark class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <pre class="overflow-x-auto bg-brand-sand/20 px-5 py-4 font-mono text-xs leading-relaxed text-brand-ink dark:bg-zinc-900/50">{{ $snippetYaml }}</pre>
                    </section>
                @endif

                {{-- KV --}}
                @if ($kind === 'kv')
                    <section class="{{ $card }}">
                        <div class="border-b border-brand-ink/10 px-5 py-3">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('KV namespaces in this Cloudflare account') }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Attach an existing namespace, or create a new one below.') }}</p>
                        </div>
                        @if (empty($kvNamespaces))
                            <p class="px-5 py-6 text-sm text-brand-moss">{{ __('No KV namespaces found in this account yet.') }}</p>
                        @else
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($kvNamespaces as $ns)
                                    @php
                                        $nsTitle = (string) ($ns['title'] ?? '');
                                        $nsId = (string) ($ns['id'] ?? '');
                                    @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-brand-ink">{{ $nsTitle ?: '—' }}</p>
                                            <p class="mt-0.5 break-all font-mono text-[11px] text-brand-mist">{{ $nsId }}</p>
                                        </div>
                                        @can('update', $site)
                                            <button type="button" wire:click="attachKvNamespace('{{ $nsId }}', '{{ $nsTitle }}')" class="{{ $btnSecondary }}">
                                                {{ __('Attach') }}
                                            </button>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    @can('update', $site)
                        <section class="{{ $card }}">
                            <div class="border-b border-brand-ink/10 px-5 py-3">
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Create new KV namespace') }}</h3>
                            </div>
                            <form wire:submit.prevent="createKvNamespace" class="flex flex-wrap items-end gap-3 px-5 py-4">
                                <label class="block min-w-[16rem] flex-1">
                                    <span class="{{ $labelCls }}">{{ __('Namespace title') }}</span>
                                    <input type="text" wire:model="newKvTitle" placeholder="my-site-kv" autocomplete="off" spellcheck="false" class="{{ $inputCls }} mt-1" />
                                </label>
                                <button type="submit" class="{{ $btnPrimary }}" wire:loading.attr="disabled" wire:target="createKvNamespace">
                                    <span wire:loading.remove wire:target="createKvNamespace">{{ __('Create namespace') }}</span>
                                    <span wire:loading wire:target="createKvNamespace">{{ __('Creating…') }}</span>
                                </button>
                            </form>
                        </section>
                    @endcan
                @endif

                {{-- R2 --}}
                @if ($kind === 'r2')
                    <section class="{{ $card }}">
                        <div class="border-b border-brand-ink/10 px-5 py-3">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('R2 buckets in this Cloudflare account') }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Bucket names are global per account — pick something descriptive.') }}</p>
                        </div>
                        @if (empty($r2Buckets))
                            <p class="px-5 py-6 text-sm text-brand-moss">{{ __('No R2 buckets found in this account yet.') }}</p>
                        @else
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($r2Buckets as $bucket)
                                    @php $name = (string) ($bucket['name'] ?? ''); @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <div class="min-w-0">
                                            <p class="truncate font-mono text-sm text-brand-ink">{{ $name ?: '—' }}</p>
                                            @if (! empty($bucket['location']))
                                                <p class="mt-0.5 text-[11px] text-brand-mist">{{ $bucket['location'] }}</p>
                                            @endif
                                        </div>
                                        @can('update', $site)
                                            <button type="button" wire:click="attachR2Bucket('{{ $name }}')" class="{{ $btnSecondary }}">
                                                {{ __('Attach') }}
                                            </button>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    @can('update', $site)
                        <section class="{{ $card }}">
                            <div class="border-b border-brand-ink/10 px-5 py-3">
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Create new R2 bucket') }}</h3>
                            </div>
                            <form wire:submit.prevent="createR2Bucket" class="flex flex-wrap items-end gap-3 px-5 py-4">
                                <label class="block min-w-[16rem] flex-1">
                                    <span class="{{ $labelCls }}">{{ __('Bucket name') }}</span>
                                    <input type="text" wire:model="newR2Name" placeholder="my-site-assets" autocomplete="off" spellcheck="false" class="{{ $inputCls }} mt-1" />
                                    <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Lowercase letters, digits, dashes. 3-64 chars.') }}</span>
                                </label>
                                <button type="submit" class="{{ $btnPrimary }}" wire:loading.attr="disabled" wire:target="createR2Bucket">
                                    <span wire:loading.remove wire:target="createR2Bucket">{{ __('Create bucket') }}</span>
                                    <span wire:loading wire:target="createR2Bucket">{{ __('Creating…') }}</span>
                                </button>
                            </form>
                        </section>
                    @endcan
                @endif

                {{-- D1 --}}
                @if ($kind === 'd1')
                    <section class="{{ $card }}">
                        <div class="border-b border-brand-ink/10 px-5 py-3">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('D1 databases in this Cloudflare account') }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('SQLite-on-Workers databases. Pick a location hint close to your users.') }}</p>
                        </div>
                        @if (empty($d1Databases))
                            <p class="px-5 py-6 text-sm text-brand-moss">{{ __('No D1 databases found in this account yet.') }}</p>
                        @else
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($d1Databases as $db)
                                    @php
                                        $dbName = (string) ($db['name'] ?? '');
                                        $dbId = (string) ($db['uuid'] ?? $db['id'] ?? '');
                                    @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-brand-ink">{{ $dbName ?: '—' }}</p>
                                            <p class="mt-0.5 break-all font-mono text-[11px] text-brand-mist">{{ $dbId }}</p>
                                        </div>
                                        @can('update', $site)
                                            <button type="button" wire:click="attachD1Database('{{ $dbId }}', '{{ $dbName }}')" class="{{ $btnSecondary }}">
                                                {{ __('Attach') }}
                                            </button>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    @can('update', $site)
                        <section class="{{ $card }}">
                            <div class="border-b border-brand-ink/10 px-5 py-3">
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Create new D1 database') }}</h3>
                            </div>
                            <form wire:submit.prevent="createD1Database" class="flex flex-wrap items-end gap-3 px-5 py-4">
                                <label class="block min-w-[16rem] flex-1">
                                    <span class="{{ $labelCls }}">{{ __('Database name') }}</span>
                                    <input type="text" wire:model="newD1Name" placeholder="my-site-db" autocomplete="off" spellcheck="false" class="{{ $inputCls }} mt-1" />
                                </label>
                                <label class="block w-44">
                                    <span class="{{ $labelCls }}">{{ __('Location hint') }}</span>
                                    <select wire:model="newD1LocationHint" class="{{ $inputCls }} mt-1">
                                        <option value="wnam">{{ __('Western North America') }}</option>
                                        <option value="enam">{{ __('Eastern North America') }}</option>
                                        <option value="weur">{{ __('Western Europe') }}</option>
                                        <option value="eeur">{{ __('Eastern Europe') }}</option>
                                        <option value="apac">{{ __('Asia-Pacific') }}</option>
                                        <option value="oc">{{ __('Oceania') }}</option>
                                    </select>
                                </label>
                                <button type="submit" class="{{ $btnPrimary }}" wire:loading.attr="disabled" wire:target="createD1Database">
                                    <span wire:loading.remove wire:target="createD1Database">{{ __('Create database') }}</span>
                                    <span wire:loading wire:target="createD1Database">{{ __('Creating…') }}</span>
                                </button>
                            </form>
                        </section>
                    @endcan
                @endif

                {{-- Declared in dply.yaml --}}
                @php
                    $declaredForKind = match ($kind) {
                        'kv' => $declaredBindings['kv'] ?? [],
                        'r2' => $declaredBindings['r2'] ?? [],
                        'd1' => $declaredBindings['d1'] ?? [],
                        default => [],
                    };
                @endphp
                <section class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-5 py-3">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Declared in your dply.yaml') }}</h3>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Read from the most recent deployment snapshot for this site.') }}</p>
                    </div>
                    @if (empty($declaredForKind))
                        <p class="px-5 py-6 text-sm text-brand-moss">{{ __('No bindings of this kind declared yet.') }}</p>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($declaredForKind as $row)
                                <li class="flex flex-wrap items-baseline justify-between gap-3 px-5 py-3 text-sm">
                                    <span class="font-mono text-brand-ink">{{ $row['binding'] ?? '—' }}</span>
                                    <span class="break-all font-mono text-xs text-brand-mist">
                                        {{ $row['id'] ?? $row['database_id'] ?? $row['bucket'] ?? $row['name'] ?? '' }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </main>
    </div>
</div>
