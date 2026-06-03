<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-brand-ink">{{ __('Deploy sync groups') }}</h1>
        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
            {{ __('Group sites across servers and projects so they deploy together. A manual deploy fans out to every member; a push webhook to the group leader deploys the rest. Manage a single site’s membership from its Settings → Repository.') }}
        </p>
    </div>

    @if ($canManage)
        {{-- Create a new group --}}
        <section class="dply-card mb-6 overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('New sync group') }}</h2>
            </div>
            <form wire:submit="createGroup" class="flex flex-wrap items-end gap-4 px-6 py-5 sm:px-7">
                <div class="grow">
                    <x-input-label for="new_group_name" :value="__('Group name')" />
                    <x-text-input id="new_group_name" wire:model="new_group_name" class="mt-2 block w-full text-sm" placeholder="{{ __('e.g. Production web tier') }}" />
                    <x-input-error :messages="$errors->get('new_group_name')" class="mt-1" />
                </div>
                <div class="grow">
                    <x-input-label for="new_group_site_id" :value="__('First site (becomes leader)')" />
                    <select id="new_group_site_id" wire:model="new_group_site_id" class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        <option value="">{{ __('Select site') }}</option>
                        @foreach ($orgSites as $os)
                            <option value="{{ $os->id }}">{{ $os->name }} ({{ $os->server?->name ?? __('Server') }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('new_group_site_id')" class="mt-1" />
                </div>
                <div class="pb-0.5">
                    <x-primary-button type="submit">{{ __('Create group') }}</x-primary-button>
                </div>
            </form>
        </section>
    @endif

    @forelse ($groups as $group)
        <section class="dply-card mb-5 overflow-hidden" wire:key="group-{{ $group->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-brand-ink">{{ $group->name }}</h2>
                    <p class="mt-0.5 text-xs text-brand-moss">
                        {{ __(':n site(s)', ['n' => $group->sites->count()]) }}
                        @if ($group->leader)· {{ __('leader: :name', ['name' => $group->leader->name]) }}@endif
                    </p>
                </div>
                @if ($canManage)
                    <div class="flex items-center gap-2">
                        <select wire:change="setRolloutMode('{{ $group->id }}', $event.target.value)" class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" title="{{ __('Parallel: all at once. Sequential: in order, stop on first failure.') }}">
                            <option value="parallel" @selected(($group->rollout_mode ?? 'parallel') === 'parallel')>{{ __('Parallel') }}</option>
                            <option value="sequential" @selected(($group->rollout_mode ?? 'parallel') === 'sequential')>{{ __('Sequential') }}</option>
                        </select>
                        <button type="button" wire:click="openConfirmActionModal('deployGroup', @js([$group->id]), @js(__('Deploy group')), @js(__('Queue a deploy for all :n site(s) in “:name”? :mode', ['n' => $group->sites->count(), 'name' => $group->name, 'mode' => ($group->rollout_mode ?? 'parallel') === 'sequential' ? __('They deploy in order and stop on the first failure.') : __('They deploy in parallel.')])), @js(__('Deploy group')), false)" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Deploy group') }}</button>
                        <button type="button" wire:click="openConfirmActionModal('deleteGroup', @js([$group->id]), @js(__('Delete sync group')), @js(__('Delete “:name”? The sites themselves are not affected.', ['name' => $group->name])), @js(__('Delete')), true)" class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50">{{ __('Delete') }}</button>
                    </div>
                @endif
            </div>

            <div class="divide-y divide-brand-ink/10">
                @foreach ($group->sites as $member)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-6 py-3 sm:px-7" wire:key="g{{ $group->id }}-s{{ $member->id }}">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('sites.show', [$member->server, $member]) }}" wire:navigate class="text-sm font-semibold text-brand-ink hover:text-brand-forest hover:underline">{{ $member->name }}</a>
                            <span class="text-xs text-brand-moss">{{ $member->server?->name ?? __('Server') }}</span>
                            @if ((string) $group->leader_site_id === (string) $member->id)
                                <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Leader') }}</span>
                            @endif
                        </div>
                        @if ($canManage)
                            <div class="flex items-center gap-2">
                                @if ((string) $group->leader_site_id !== (string) $member->id)
                                    <button type="button" wire:click="setLeader('{{ $group->id }}', '{{ $member->id }}')" class="rounded-lg border border-brand-ink/15 px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Make leader') }}</button>
                                @endif
                                <button type="button" wire:click="removeSite('{{ $member->id }}')" class="rounded-lg border border-rose-200 px-2.5 py-1 text-[11px] font-medium text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($canManage)
                <div class="flex flex-wrap items-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-3 sm:px-7">
                    <div class="grow">
                        <select wire:model="add_site_for_group.{{ $group->id }}" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                            <option value="">{{ __('Add a site…') }}</option>
                            @foreach ($orgSites as $os)
                                <option value="{{ $os->id }}">{{ $os->name }} ({{ $os->server?->name ?? __('Server') }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" wire:click="addSite('{{ $group->id }}')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Add site') }}</button>
                </div>
            @endif
        </section>
    @empty
        <div class="dply-card px-6 py-10 text-center text-sm text-brand-moss">
            {{ __('No deploy sync groups yet.') }}
            @if ($canManage) {{ __('Create one above to deploy multiple sites together.') }} @endif
        </div>
    @endforelse

    @include('livewire.partials.confirm-action-modal')
</div>
