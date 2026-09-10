{{--
    Database: health, where the weight is (largest tables, autoloaded options),
    cleanup, and snapshots. Reads are instant wp-cli calls; every change is
    queued. The SQL cleanups are admin/owner only — deleted rows do not come back.
--}}
@php
    $fmtBytes = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : number_format($b / 1024, 1).' KB';
    $snapshotPending = $snapshots->contains('status', \App\Models\Snapshot::STATUS_PENDING);
@endphp
<div>
    @error('database')
        <p class="border-b border-rose-200/70 bg-rose-50/60 px-3 py-2 text-xs text-rose-700 sm:px-4">{{ $message }}</p>
    @enderror

    {{-- Remote access: connection facts + an ssh -L tunnel for TablePlus /
         DBeaver / the mysql client. Read-only — the password never enters the
         DOM; it lives in the site's own config. --}}
    @if ($dbRemote)
        @php $dbTarget = $dbRemote['target']; @endphp
        <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Remote access') }}</p>
                    <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Connect TablePlus, DBeaver, DataGrip or the mysql client through an SSH tunnel to this server.') }}</p>
                </div>
                <a href="{{ route('servers.databases', $site->server) }}" class="text-xs font-semibold text-brand-ink underline decoration-brand-sage underline-offset-2 hover:text-brand-forest">{{ __('Server database settings') }}</a>
            </div>
            <dl class="mt-2 grid min-w-0 grid-cols-2 gap-x-4 gap-y-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3 sm:grid-cols-5">
                @foreach ([__('SSH') => $dbRemote['ssh'], __('Host') => $dbTarget->host, __('Port') => $dbTarget->port, __('Database') => $dbTarget->database, __('User') => $dbTarget->username] as $dbLabel => $dbValue)
                    <div class="min-w-0">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ $dbLabel }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $dbValue }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($dbRemote['tunnel'])
                <div class="mt-2 min-w-0">
                    <x-cli-snippet :commands="[
                        ['label' => __('Tunnel'), 'command' => $dbRemote['tunnel']['tunnel']],
                        ['label' => __('Connection URI'), 'command' => $dbRemote['tunnel']['uri']],
                        ['label' => __('Terminal client'), 'command' => $dbRemote['tunnel']['connect']],
                    ]" :summary="__('Tunnel commands')" />
                </div>
            @else
                <p class="mt-2 text-xs text-brand-moss">{{ __('The site’s server is not ready for SSH, so a tunnel cannot be built yet.') }}</p>
            @endif
            <p class="mt-2 text-xs text-brand-moss">{{ __('The password is not shown here — it is DB_PASSWORD in this site’s wp-config.php (or .env on Bedrock).') }}</p>
        </div>
    @endif

    {{-- Health first: size and integrity are what you check before deciding
         whether a snapshot or a repair is the next move. --}}
    <div class="border-b border-brand-ink/10">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Database health') }}</h3>
                <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Size on disk, a table integrity check, and the two repair routines.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-arrow-path" target="loadDbHealth" wire:click="loadDbHealth">{{ __('Check') }}</x-spinner-button>
                <x-spinner-button size="xs" variant="secondary" type="button" target="optimizeDatabase" wire:click="optimizeDatabase">{{ __('Optimize') }}</x-spinner-button>
                <x-spinner-button size="xs" variant="secondary" type="button" target="repairDatabase" wire:click="repairDatabase">{{ __('Repair') }}</x-spinner-button>
            </div>
        </div>
        @if ($dbHealth !== null)
            <div class="px-3 py-3 sm:px-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 font-mono text-2xs text-brand-ink ring-1 ring-brand-ink/10">{{ $dbHealth['size'] ?: __('size unknown') }}</span>
                    <span @class([
                        'inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1',
                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $dbHealth['ok'],
                        'bg-rose-50 text-rose-800 ring-rose-200' => ! $dbHealth['ok'],
                    ])>{{ $dbHealth['ok'] ? __('tables ok') : __('needs attention') }}</span>
                </div>
                @if (! empty($dbHealth['check']))
                    <pre class="mt-2 max-h-40 overflow-auto rounded-md bg-brand-sand/50 p-3 font-mono text-2xs leading-relaxed text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $dbHealth['check'] }}</pre>
                @endif
            </div>
        @endif
    </div>

    {{-- 1. Largest tables --}}
    <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Largest tables') }}</p>
                <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Where the space goes — a bloated options, postmeta or plugin log table usually stands out.') }}</p>
            </div>
            <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-table-cells" target="loadDbTables" wire:click="loadDbTables">{{ $dbTables === null ? __('Load') : __('Refresh') }}</x-spinner-button>
        </div>
        @if ($dbTables !== null && $dbTables !== [])
            @php $maxBytes = max(1, $dbTables[0]['bytes']); @endphp
            <ul class="mt-2 space-y-1">
                @foreach (array_slice($dbTables, 0, 12) as $table)
                    <li class="grid grid-cols-[minmax(0,1fr)_5rem] items-center gap-3 text-xs" wire:key="db-table-{{ $table['name'] }}">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-brand-ink">{{ $table['name'] }}</p>
                            <div class="mt-0.5 h-1.5 rounded-full bg-brand-ink/[0.06]"><div class="h-1.5 rounded-full bg-brand-forest/70" style="width: {{ max(1, (int) round($table['bytes'] / $maxBytes * 100)) }}%"></div></div>
                        </div>
                        <p class="text-right font-mono text-brand-moss">{{ $fmtBytes($table['bytes']) }}</p>
                    </li>
                @endforeach
            </ul>
            @if (count($dbTables) > 12)
                <p class="mt-1.5 text-2xs text-brand-mist">{{ __('+ :n smaller tables', ['n' => count($dbTables) - 12]) }}</p>
            @endif
        @endif
    </div>

    {{-- 2. Autoloaded options --}}
    @if ($canMutate)
        <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ __('Autoloaded options') }}
                        @if ($autoloadAudit)
                            <span @class([
                                'ml-1 rounded-full px-2 py-0.5 text-2xs font-semibold ring-1',
                                'bg-emerald-50 text-emerald-800 ring-emerald-200' => $autoloadAudit['total'] < 800000,
                                'bg-amber-50 text-amber-800 ring-amber-200' => $autoloadAudit['total'] >= 800000,
                            ])>{{ $fmtBytes($autoloadAudit['total']) }} · {{ $autoloadAudit['count'] }}</span>
                        @endif
                    </p>
                    <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Loaded on every single request. WordPress Site Health warns past 800 KB — usually one plugin storing far too much.') }}</p>
                </div>
                <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-bolt" target="loadAutoloadAudit" wire:click="loadAutoloadAudit">{{ $autoloadAudit === null ? __('Audit') : __('Refresh') }}</x-spinner-button>
            </div>
            @if ($autoloadAudit && $autoloadAudit['top'] !== [])
                <ul class="mt-2 divide-y divide-brand-ink/10 rounded-md border border-brand-ink/10 bg-white">
                    @foreach ($autoloadAudit['top'] as $option)
                        <li class="flex items-center justify-between gap-3 px-2.5 py-1.5 text-xs" wire:key="autoload-{{ $option['name'] }}">
                            <span class="min-w-0 truncate font-mono text-brand-ink">{{ $option['name'] }}</span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="font-mono text-brand-moss">{{ $fmtBytes($option['bytes']) }}</span>
                                {{-- Admin/owner: `option set-autoload` runs at the Destructive tier. --}}
                                @if ($canDestroy)
                                    <button type="button" wire:click="stopAutoloading(@js($option['name']))" class="rounded border border-brand-ink/15 px-1.5 py-0.5 text-2xs font-medium text-brand-ink hover:bg-brand-sand/40" title="{{ __('Still works — it just stops loading on every request') }}">{{ __('Stop autoloading') }}</button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- 3. Cleanup --}}
    @if ($canMutate)
        <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Clean up') }}</p>
            <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Clears the leftovers that pile up over years. Each asks first; the permanent ones need an admin or owner.') }}</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @foreach ([
                    'transients' => [__('Expired transients'), __('Stale cached values'), false],
                    'revisions' => [__('Post revisions'), __('Every saved revision, and its metadata'), true],
                    'drafts' => [__('Auto-drafts'), __('Empty drafts from opened editors'), true],
                    'comments' => [__('Spam & trashed comments'), __('With their metadata'), true],
                ] as $kind => [$label, $hint, $permanent])
                    @if (! $permanent || $canDestroy)
                        <button type="button" wire:click="confirmDbCleanup('{{ $kind }}')" class="flex items-center justify-between gap-2 rounded-md border border-brand-ink/10 bg-white px-2.5 py-2 text-left transition hover:bg-brand-sand/30">
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold text-brand-ink">{{ $label }}</span>
                                <span class="block text-2xs text-brand-mist">{{ $hint }}</span>
                            </span>
                            <x-heroicon-o-trash @class(['h-4 w-4 shrink-0', 'text-rose-600' => $permanent, 'text-brand-moss' => ! $permanent]) aria-hidden="true" />
                        </button>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- 4 + 5. Snapshots: queued, with status — a failed dump now says why. --}}
    <div class="border-b border-brand-ink/10 last:border-b-0" @if ($snapshotPending) wire:poll.5s @endif>
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Database snapshots') }}</h3>
                <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Manual `mysqldump` snapshots, stored on this server (7-day TTL) or your configured S3 bucket. The dump runs in the background.') }}</p>
            </div>
            <x-spinner-button size="xs" variant="primary" type="button" icon="heroicon-o-camera" target="takeSnapshot" wire:click="takeSnapshot" class="ml-auto shrink-0">{{ __('Take snapshot') }}</x-spinner-button>
        </div>

        <div class="px-3 py-2.5 sm:px-4">
            <x-input-error :messages="$errors->get('snapshots')" class="mb-3" />

            @if ($snapshots->isEmpty())
                <p class="py-4 text-center text-sm text-brand-mist">{{ __('No snapshots yet. Click "Take snapshot" to capture one.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10 rounded-md border border-brand-ink/10 bg-white">
                    @foreach ($snapshots as $snapshot)
                        <li class="px-3 py-2" wire:key="snapshot-{{ $snapshot->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-sm text-brand-ink">snap-{{ $snapshot->id }}</p>
                                    <p class="mt-0.5 text-xs text-brand-mist">
                                        {{ $snapshot->reason }} · {{ number_format(($snapshot->bytes ?? 0) / 1024, 1) }}&nbsp;KB · {{ $snapshot->created_at?->diffForHumans() }}
                                        @if ($snapshot->expires_at)
                                            · expires {{ $snapshot->expires_at->diffForHumans() }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1',
                                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $snapshot->status === \App\Models\Snapshot::STATUS_COMPLETED,
                                        'bg-amber-50 text-amber-800 ring-amber-200' => $snapshot->status === \App\Models\Snapshot::STATUS_PENDING,
                                        'bg-rose-50 text-rose-800 ring-rose-200' => $snapshot->status === \App\Models\Snapshot::STATUS_FAILED,
                                    ])>
                                        @if ($snapshot->status === \App\Models\Snapshot::STATUS_PENDING)
                                            <x-spinner size="sm" />
                                        @endif
                                        {{ $snapshot->status ?? '—' }}
                                    </span>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                        'bg-brand-sand/40 text-brand-ink' => $snapshot->destination === 'local_disk',
                                        'bg-brand-sage/15 text-brand-forest' => $snapshot->destination === 's3',
                                    ])>{{ $snapshot->destination }}</span>
                                </div>
                            </div>
                            @if ($snapshot->status === \App\Models\Snapshot::STATUS_FAILED && $snapshot->error_message)
                                <p class="mt-1.5 rounded bg-rose-50 px-2 py-1 font-mono text-2xs text-rose-800">{{ \Illuminate\Support\Str::limit($snapshot->error_message, 400) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
