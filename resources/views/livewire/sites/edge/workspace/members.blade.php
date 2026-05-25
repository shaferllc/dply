<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h3 class="inline-flex items-center gap-2 text-base font-semibold text-brand-ink">
                        <x-heroicon-o-user-group class="h-4 w-4 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                        {{ __('Site members') }}
                    </h3>
                    <p class="mt-0.5 text-sm text-brand-moss">
                        {{ __('Per-site role grants stack on top of organization roles — they only elevate access, never restrict it. Anyone already an org admin keeps full access.') }}
                    </p>
                </div>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                    {{ $members->count() }} {{ trans_choice('member|members', $members->count()) }}
                </span>
            </div>
        </div>

        @if ($canManage)
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <form wire:submit.prevent="addMember" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_12rem_auto]">
                    <div>
                        <label for="invite-email" class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Email of an org member') }}</label>
                        <input
                            id="invite-email"
                            type="email"
                            wire:model="inviteEmail"
                            class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-sm text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                            placeholder="teammate@example.com"
                            autocomplete="off"
                        />
                        @error('inviteEmail')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="invite-role" class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Role') }}</label>
                        <select
                            id="invite-role"
                            wire:model="inviteRole"
                            class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-sm text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                        >
                            @foreach ($roleOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="dply-btn dply-btn-primary">
                            {{ __('Add member') }}
                        </button>
                    </div>
                </form>
                <dl class="mt-3 grid grid-cols-1 gap-1 text-[11px] text-brand-mist sm:grid-cols-3">
                    @foreach ($roleOptions as $option)
                        <div>
                            <dt class="font-semibold text-brand-moss">{{ $option['label'] }}</dt>
                            <dd>{{ $option['hint'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        @if ($members->isEmpty())
            <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                {{ __('No per-site members yet — only organization roles apply.') }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-brand-ink/8 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                        <tr>
                            <th class="px-4 py-2 sm:px-6">{{ __('Member') }}</th>
                            <th class="px-4 py-2">{{ __('Role') }}</th>
                            <th class="px-4 py-2">{{ __('Added') }}</th>
                            <th class="px-4 py-2 text-right sm:px-6">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                        @foreach ($members as $member)
                            <tr wire:key="member-{{ $member->id }}">
                                <td class="px-4 py-3 sm:px-6">
                                    <div class="font-medium text-brand-ink">{{ $member->user?->name ?: $member->user?->email }}</div>
                                    <div class="font-mono text-[11px] text-brand-mist">{{ $member->user?->email }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($canManage)
                                        <select
                                            wire:change="updateRole('{{ $member->id }}', $event.target.value)"
                                            class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                                        >
                                            @foreach ($roleOptions as $option)
                                                <option value="{{ $option['value'] }}" @selected($member->role === $option['value'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[11px] uppercase tracking-wide text-brand-moss">{{ $member->role }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-brand-moss">
                                    <span title="{{ $member->created_at?->toIso8601String() }}">{{ $member->created_at?->diffForHumans() ?? '—' }}</span>
                                    @if ($member->invitedBy)
                                        <div class="text-[11px] text-brand-mist">{{ __('by :name', ['name' => $member->invitedBy->name ?: $member->invitedBy->email]) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right sm:px-6">
                                    @if ($canManage)
                                        <button
                                            type="button"
                                            wire:click="removeMember('{{ $member->id }}')"
                                            wire:confirm="{{ __('Remove this member from the site?') }}"
                                            class="text-xs font-semibold text-rose-600 hover:text-rose-700"
                                        >
                                            {{ __('Remove') }}
                                        </button>
                                    @else
                                        <span class="text-[11px] text-brand-mist">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
