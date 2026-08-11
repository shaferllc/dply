@php
    $ns = $queueNamespace;
    $paused = $ns->status === \App\Modules\Queue\Models\QueueNamespace::STATUS_PAUSED;

    $statusTone = [
        \App\Modules\Queue\Models\QueueNamespace::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_PAUSED => 'bg-amber-100 text-amber-700 ring-amber-200',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];

    // What the customer pastes into .env. The connection is `dply` rather than
    // the app's own `sqs` block on purpose — that block reads AWS_ACCESS_KEY_ID,
    // so pointing it here would hand the queue the app's S3 credentials.
    $envSample = collect([
        'QUEUE_CONNECTION=dply',
        'DPLY_QUEUE_URL='.($endpoint !== '' ? $endpoint : 'https://…/api/queue/v1/'.$ns->id),
        'DPLY_QUEUE_KEY=<access key id>',
        'DPLY_QUEUE_SECRET=<secret>',
    ])->implode("\n");
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="queues"
            :title="$ns->name"
            :description="__('A managed job queue. Apps reach it over the SQS wire protocol, so Laravel’s built-in driver works against it unchanged.')"
            icon="heroicon-o-queue-list"
            :breadcrumb="$breadcrumbs"
        >
            <x-slot:actions>
                @if ($canUpdate)
                    <x-secondary-button type="button" wire:click="togglePause">
                        @if ($paused)
                            <x-heroicon-o-play class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Resume') }}
                        @else
                            <x-heroicon-o-pause class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Pause') }}
                        @endif
                    </x-secondary-button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="{{ __('Queue at a glance') }}">
                    <x-fleet-stat :label="__('Status')">
                        <p class="mt-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$ns->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                                {{ ucfirst($ns->status) }}
                            </span>
                        </p>
                        <p class="mt-1 text-xs text-brand-mist">
                            {{ $paused ? __('Pushes rejected; drains still work') : __('Accepting pushes') }}
                        </p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Queued now')">
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">
                            {{ $depth ? number_format($depth['total']) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-brand-mist tabular-nums">
                            @if ($depth)
                                {{ __(':pending pending · :reserved in flight', [
                                    'pending' => number_format($depth['pending']),
                                    'reserved' => number_format($depth['reserved']),
                                ]) }}
                            @else
                                {{ __('Depth unavailable') }}
                            @endif
                        </p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Jobs this month')">
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($usedJobs) }}</p>
                        <p @class(['mt-1 text-xs', 'text-amber-700' => $overIncluded, 'text-brand-mist' => ! $overIncluded])>
                            @if ($entitlement->monthlyIncludedJobs > 0)
                                {{ __('of :included included', ['included' => number_format($entitlement->monthlyIncludedJobs)]) }}
                            @else
                                {{ __('Unlimited on this plan') }}
                            @endif
                        </p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Credentials')">
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ $liveCredentialCount }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('of :max live', ['max' => $maxLiveCredentials]) }}</p>
                    </x-fleet-stat>
                </dl>
            </x-slot:stats>

            {{-- The one and only showing. dply holds an encrypted copy because
                 SigV4 has to recompute the HMAC, but displaying it again would
                 turn a database read into a credential disclosure. --}}
            @if ($revealedSecret)
                <section class="border-b border-brand-ink/10 bg-amber-50 px-5 py-5 sm:px-6">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-exclamation-triangle class="h-6 w-6 shrink-0 text-amber-600" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-amber-900">{{ __('Copy this secret now — it is shown once') }}</h2>
                            <p class="mt-1 text-sm text-amber-800">
                                {{ __('Put it in your app’s .env as DPLY_QUEUE_SECRET. dply will not show it again; if you lose it, mint a new credential and revoke this one.') }}
                            </p>
                            <pre class="mt-3 overflow-x-auto rounded-lg border border-amber-300 bg-white p-3 font-mono text-xs text-brand-ink">{{ $revealedSecret }}</pre>
                            <div class="mt-3">
                                <x-secondary-button type="button" wire:click="dismissSecret">{{ __('I have saved it') }}</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            @if ($paused)
                <div class="border-b border-brand-ink/10 bg-amber-50 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-pause-circle class="h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-amber-900">{{ __('This queue is paused') }}</p>
                            <p class="mt-0.5 text-sm text-amber-800">
                                {{ __('Pushes are rejected with a throttling error. Workers can still receive, so anything already queued drains normally.') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Connecting --}}
            <section class="border-b border-brand-ink/10">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <x-icon-badge>
                        <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Connecting') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Point an app at this queue') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Sites dply deploys are wired automatically. For anything else, these four keys are the whole setup.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4 px-5 py-5 sm:px-6">
                    @if ($endpoint === '')
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                            {{ __('No public URL is configured for dply Queue on this installation, so there is no endpoint to publish yet. Set DPLY_QUEUE_PUBLIC_URL.') }}
                        </div>
                    @else
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Endpoint') }}</p>
                            <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $endpoint }}</p>
                        </div>
                    @endif

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('.env') }}</p>
                        <pre class="mt-1 overflow-x-auto rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3 font-mono text-xs leading-relaxed text-brand-ink">{{ $envSample }}</pre>
                    </div>

                    {{-- Two honest limits, said here rather than discovered in
                         production. Both come straight from the ADR. --}}
                    <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs leading-relaxed text-brand-moss">
                        <p>
                            {{ __('An external app also needs one line in config/queue.php: the stock sqs block has no endpoint key, and without one the AWS SDK routes to real AWS regardless of the URL above.') }}
                        </p>
                        <p class="mt-1.5">
                            {{ __('Delivery is not strictly FIFO under concurrency — jobs are claimed with SKIP LOCKED, so a busy queue can hand out work slightly out of order. Horizon needs Redis and does not work against this driver.') }}
                        </p>
                    </div>
                </div>
            </section>

            {{-- Credentials --}}
            <section class="border-b border-brand-ink/10">
                <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <x-icon-badge>
                        <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Access') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Credentials') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Rotate by minting a second credential, redeploying onto it, then revoking the first once it goes quiet — a credential only reaches a running app on its next deploy, so revoking first guarantees an outage.') }}
                        </p>
                    </div>
                    @if ($canManageCredentials && $liveCredentialCount < $maxLiveCredentials)
                        <x-secondary-button type="button" wire:click="startMint" class="shrink-0 text-xs">
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Mint credential') }}
                        </x-secondary-button>
                    @endif
                </div>

                @if ($credentials->isEmpty())
                    <div class="px-5 py-8 text-center text-sm text-brand-moss sm:px-6">
                        {{ __('No credentials — nothing can reach this queue.') }}
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($credentials as $credential)
                            <li wire:key="cred-{{ $credential->id }}" class="px-5 py-4 sm:px-6">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $credential->name }}</p>
                                            @if ($credential->isRevoked())
                                                <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-200">{{ __('Revoked') }}</span>
                                            @elseif ($credential->isExpired())
                                                <span class="inline-flex items-center rounded-full bg-brand-sand/55 px-2 py-0.5 text-xs font-medium text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ __('Expired') }}</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-xs font-medium text-brand-forest ring-1 ring-inset ring-brand-sage/25">{{ __('Live') }}</span>
                                            @endif
                                            @if ($revealedCredentialId === $credential->id)
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-200">{{ __('Just minted') }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 font-mono text-xs text-brand-moss">{{ $credential->accessKeyId() }}</p>
                                        <p class="mt-0.5 text-xs text-brand-mist">
                                            {{ __('Created :created', ['created' => $credential->created_at?->diffForHumans()]) }}
                                            @if ($credential->last_used_at)
                                                · {{ __('last used :used', ['used' => $credential->last_used_at->diffForHumans()]) }}
                                            @else
                                                · {{ __('never used') }}
                                            @endif
                                        </p>
                                    </div>

                                    @if ($canManageCredentials && ! $credential->isRevoked())
                                        <button type="button" wire:click="confirmRevoke('{{ $credential->id }}')"
                                                class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700">
                                            <x-heroicon-o-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Revoke') }}
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Danger zone --}}
            @if ($canDelete)
                <section class="px-5 py-5 sm:px-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50/60 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Delete this queue') }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ __('Destroys every queued job and revokes every credential. There is no undo.') }}
                            </p>
                        </div>
                        <button type="button" wire:click="confirmDelete" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-red-600 hover:text-red-700">
                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Delete') }}
                        </button>
                    </div>
                </section>
            @endif
        </x-organization-shell>
    </div>

    {{-- Mint --}}
    <x-modal name="queue-credential-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <form wire:submit="mintCredential">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Mint credential') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $ns->name }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('The new secret is shown once, on this page. The existing credential keeps working until you revoke it.') }}
                    </p>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6">
                <div>
                    <x-input-label for="credentialName" :value="__('Name (optional)')" />
                    <x-text-input id="credentialName" wire:model="credentialName" type="text" class="mt-1 block w-full" maxlength="120" autofocus />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Defaults to today’s date, so a rotation is easy to spot later.') }}</p>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelMint">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="mintCredential">
                    {{ __('Mint credential') }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- Revoke --}}
    <x-modal name="queue-revoke-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <form wire:submit="revokeCredential">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Revoke credential') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('This takes effect immediately') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Any app still holding this secret starts failing on its next push or receive. If it is still in use, deploy the replacement first.') }}
                    </p>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelRevoke">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="submit" wire:loading.attr="disabled" wire:target="revokeCredential">
                    {{ __('Revoke') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>

    {{-- Delete --}}
    <x-modal name="queue-delete-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <form wire:submit="deleteNamespace">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Delete queue') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $ns->name }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Every job still queued is destroyed and every credential is revoked. There is no undo.') }}
                    </p>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6">
                <div>
                    <x-input-label for="deleteConfirmationShow" :value="__('Type :name to confirm', ['name' => $ns->name])" />
                    <x-text-input id="deleteConfirmationShow" wire:model="deleteConfirmation" type="text" class="mt-1 block w-full font-mono" autocomplete="off" />
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelDelete">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="submit" wire:loading.attr="disabled" wire:target="deleteNamespace">
                    {{ __('Delete queue') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</div>
