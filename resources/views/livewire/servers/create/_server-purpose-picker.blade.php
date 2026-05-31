{{--
  Compact server purpose picker for Step 2 (before region & size).
  Required: $provisionOptions (server_roles), $form (ServerCreateForm)
--}}
@php
    $serverRoleCardIcons = [
        'application' => 'heroicon-o-globe-alt',
        'load_balancer' => 'heroicon-o-arrows-right-left',
        'database' => 'heroicon-o-circle-stack',
        'redis' => 'heroicon-o-bolt',
        'valkey' => 'heroicon-o-bolt',
        'worker' => 'heroicon-o-cpu-chip',
        'docker' => 'heroicon-o-cube-transparent',
        'plain' => 'heroicon-o-wrench-screwdriver',
    ];
    $roles = collect($provisionOptions['server_roles'] ?? [])
        ->reject(fn (array $role): bool => ($role['id'] ?? '') === 'valkey');
@endphp

<section class="dply-card overflow-visible">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7 rounded-t-2xl">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-flag class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Purpose') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('What is this server for?') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Plan sizes are tuned to this choice. App servers pick a stack template next; dedicated roles review what gets installed.') }}</p>
        </div>
        <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Required') }}</span>
    </div>
    <div class="p-6 sm:p-7">
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($roles as $role)
                @php
                    $roleId = (string) ($role['id'] ?? '');
                    $roleSelected = $form->server_role === $roleId;
                    $roleIcon = $serverRoleCardIcons[$roleId] ?? 'heroicon-o-server-stack';
                @endphp
                <button
                    type="button"
                    wire:key="step2-server-role-{{ $roleId }}"
                    wire:click="chooseServerRole('{{ $roleId }}')"
                    wire:loading.attr="disabled"
                    wire:target="chooseServerRole"
                    aria-pressed="{{ $roleSelected ? 'true' : 'false' }}"
                    @class([
                        'group flex w-full items-start gap-3 rounded-xl border-2 p-3 text-left transition-all disabled:cursor-wait disabled:opacity-70',
                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $roleSelected,
                        'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:bg-brand-sand/20' => ! $roleSelected,
                    ])
                >
                    <span @class([
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ring-1 transition-colors',
                        'bg-brand-sage text-white ring-brand-sage/30' => $roleSelected,
                        'bg-brand-sage/15 text-brand-forest ring-brand-sage/25 group-hover:bg-brand-sage/20' => ! $roleSelected,
                    ])>
                        <x-dynamic-component :component="$roleIcon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-brand-ink">{{ $role['label'] ?? $roleId }}</span>
                        @if (! empty($role['summary']))
                            <span class="mt-0.5 block text-xs leading-snug text-brand-moss line-clamp-2">{{ $role['summary'] }}</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('form.server_role')" class="mt-3" />
    </div>
</section>
