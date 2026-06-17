{{--
  Dedicated database host options on Step 3 — initial database, credentials, network.
  Required: $form, $operatorPublicIp (optional)
--}}
@php
    use App\Support\Servers\DedicatedDatabaseServerProvisionConfig;

    $supportsRemote = DedicatedDatabaseServerProvisionConfig::engineSupportsRemoteAccess((string) $form->database);
    $networkMode = $form->database_remote_access ? 'remote' : 'local';
    $dbPort = DedicatedDatabaseServerProvisionConfig::fromServer(null, (string) $form->database)->defaultPort();
@endphp

<div class="space-y-8">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="database_initial_name" :value="__('Database name')" />
            <x-text-input
                id="database_initial_name"
                wire:model.live.debounce.400ms="form.database_initial_name"
                type="text"
                class="mt-2 block w-full font-mono text-sm"
                placeholder="app"
                autocomplete="off"
            />
            <p class="mt-1 text-xs text-brand-mist">{{ __('Letters, digits, and underscores. Created during provision.') }}</p>
            <x-input-error :messages="$errors->get('form.database_initial_name')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="database_username" :value="__('Username')" />
            <x-text-input
                id="database_username"
                wire:model.live.debounce.400ms="form.database_username"
                type="text"
                class="mt-2 block w-full font-mono text-sm"
                placeholder="dply_app"
                autocomplete="off"
            />
            <x-input-error :messages="$errors->get('form.database_username')" class="mt-1" />
        </div>
    </div>

    <div class="rounded-xl border border-brand-sage/25 bg-white p-4">
        <x-password-field
            id="database_password"
            :label="__('Database password')"
            wire:model.live="form.database_password"
            placeholder="••••••••••••"
            class="font-mono text-sm"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="generateDedicatedDatabasePassword"
                    class="font-medium text-brand-sage hover:underline"
                >
                    {{ __('Generate') }}
                </button>
            </x-slot:actions>
        </x-password-field>
        <x-input-error :messages="$errors->get('form.database_password')" class="mt-1" />
    </div>

    @if ($supportsRemote)
        <div class="rounded-2xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream/30 via-white to-white p-5 sm:p-6">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Network access') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-mist">
                        {{ __('Dedicated database hosts usually serve app servers on your VPC. Pick a trusted CIDR and dply opens the engine port in UFW.') }}
                    </p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                <button
                    type="button"
                    wire:click="chooseDatabaseNetworkAccess('local')"
                    wire:loading.attr="disabled"
                    wire:target="chooseDatabaseNetworkAccess"
                    aria-pressed="{{ $networkMode === 'local' ? 'true' : 'false' }}"
                    @class([
                        'flex flex-col rounded-2xl border-2 p-4 text-left transition-all',
                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/25 ring-offset-2 ring-offset-white' => $networkMode === 'local',
                        'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:bg-brand-sand/10' => $networkMode !== 'local',
                    ])
                >
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Localhost only') }}</span>
                    <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Bind to 127.0.0.1 — SSH tunnel or on-box clients only.') }}</p>
                </button>

                <button
                    type="button"
                    wire:click="chooseDatabaseNetworkAccess('remote')"
                    wire:loading.attr="disabled"
                    wire:target="chooseDatabaseNetworkAccess"
                    aria-pressed="{{ $networkMode === 'remote' ? 'true' : 'false' }}"
                    @class([
                        'flex flex-col rounded-2xl border-2 p-4 text-left transition-all',
                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/25 ring-offset-2 ring-offset-white' => $networkMode === 'remote',
                        'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:bg-brand-sand/10' => $networkMode !== 'remote',
                    ])
                >
                    <span class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-brand-ink">{{ __('Other servers on my network') }}</span>
                        <span class="rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/25">{{ __('Recommended') }}</span>
                    </span>
                    <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Listen on all interfaces, open port :port, and add a UFW allow rule for a trusted CIDR.', ['port' => $dbPort]) }}</p>
                </button>
            </div>

            @if ($networkMode === 'remote')
                <div class="mt-4 rounded-xl border border-brand-sage/25 bg-white p-4">
                    <x-input-label for="database_allowed_from" :value="__('Allow from (CIDRs / IPs)')" />
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start">
                        <x-text-input
                            id="database_allowed_from"
                            wire:model.live.debounce.400ms="form.database_allowed_from"
                            type="text"
                            @class([
                                'block w-full font-mono text-sm sm:max-w-sm',
                                'border-amber-400 ring-amber-200/80' => $form->database_allowed_from === '' || ! DedicatedDatabaseServerProvisionConfig::isAllowedSourceCidr($form->database_allowed_from),
                            ])
                            placeholder="10.0.0.0/8, 203.0.113.42/32"
                            autocomplete="off"
                        />
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $exampleCidr)
                                @php
                                    $current = trim((string) $form->database_allowed_from);
                                    $nextAllowedFrom = $current === '' ? $exampleCidr : $current.', '.$exampleCidr;
                                @endphp
                                <button
                                    type="button"
                                    wire:click="$set('form.database_allowed_from', @js($nextAllowedFrom))"
                                    class="rounded-full bg-brand-sand/40 px-2.5 py-1 font-mono text-[11px] font-medium text-brand-forest transition hover:bg-brand-sage/15 hover:ring-1 hover:ring-brand-sage/30"
                                >
                                    + {{ $exampleCidr }}
                                </button>
                            @endforeach
                            @if (! empty($operatorPublicIp ?? null))
                                @php
                                    $currentForIp = trim((string) $form->database_allowed_from);
                                    $nextWithIp = $currentForIp === '' ? $operatorPublicIp.'/32' : $currentForIp.', '.$operatorPublicIp.'/32';
                                @endphp
                                <button
                                    type="button"
                                    wire:click="$set('form.database_allowed_from', @js($nextWithIp))"
                                    class="rounded-full bg-emerald-50 px-2.5 py-1 font-mono text-[11px] font-medium text-emerald-800 ring-1 ring-emerald-200 transition hover:bg-emerald-100"
                                >
                                    + {{ __('your IP') }} ({{ $operatorPublicIp }}/32)
                                </button>
                            @endif
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-brand-mist">{{ __('Comma- or space-separated private CIDRs. 0.0.0.0/0 is blocked from this wizard.') }}</p>
                    @if ($form->database_allowed_from === '')
                        <p class="mt-2 inline-flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-snug text-amber-950 ring-1 ring-amber-200/80">
                            <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Required: pick a CIDR above or switch to Localhost only.') }}
                        </p>
                    @endif
                    <x-input-error :messages="$errors->get('form.database_allowed_from')" class="mt-1" />
                </div>
            @endif
        </div>
    @endif

    <div class="rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.02] p-4 sm:p-5">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Provision preview') }}</p>
        <ul class="mt-3 space-y-2 text-sm text-brand-ink">
            <li class="flex items-start gap-2">
                <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <span>{{ __('Create database :name with user :user.', ['name' => $form->database_initial_name, 'user' => $form->database_username]) }}</span>
            </li>
            <li class="flex items-start gap-2">
                <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <span>
                    @if ($networkMode === 'remote' && $supportsRemote && $form->database_allowed_from !== '')
                        {{ __('Listen on all interfaces and allow TCP :port from :cidr.', ['port' => $dbPort, 'cidr' => $form->database_allowed_from]) }}
                    @elseif ($networkMode === 'remote' && $supportsRemote)
                        {{ __('Listen on all interfaces — pick a source CIDR above.') }}
                    @else
                        {{ __('Bind to localhost only.') }}
                    @endif
                </span>
            </li>
        </ul>
    </div>
</div>
