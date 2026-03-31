{{--
  Livewire: deleteConfirmName, removeMode, scheduledRemovalDate, deletionReason,
  deletePhraseControl, deleteAckCloud, deleteAckSites, currentPassword
  Methods: closeRemoveServerModal, submitRemoveServer, applyRemovalDatePreset
  Props: $open (bool), $serverName (string), $serverId (string), $deletionSummary (?array)
--}}
@php
    $summary = $deletionSummary ?? null;
    $docsUrl = config('dply.server_deletion_docs_url');
    $sitesUrl = filled($serverId ?? null) ? route('servers.sites', $serverId) : null;
@endphp
@if ($open)
    <div
        class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="remove-server-modal-title"
    >
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeRemoveServerModal" wire:key="remove-server-backdrop"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
            <div
                class="my-auto w-full max-w-xl rounded-2xl border border-brand-ink/10 bg-white shadow-xl"
                @click.stop
                wire:key="remove-server-dialog"
            >
            <form wire:submit="submitRemoveServer" class="flex flex-col">
                <div class="border-b border-zinc-100 px-6 py-6 sm:px-8 sm:py-7">
                    <h2 id="remove-server-modal-title" class="text-lg font-semibold text-brand-ink">{{ __('Remove server') }}</h2>
                    <p class="mt-3 text-sm text-brand-moss leading-relaxed sm:mt-4">
                        {{ __('Removing a server deletes its record in Dply. When a cloud provider instance is linked, we attempt to destroy that resource as well. This cannot be undone.') }}
                    </p>
                </div>
                <div class="space-y-7 px-6 py-7 sm:space-y-8 sm:px-8 sm:py-8 max-h-[min(70vh,36rem)] overflow-y-auto">
                    @if (is_array($summary))
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 text-sm text-brand-ink sm:p-6">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Impact summary') }}</p>
                            <ul class="mt-3 list-inside list-disc space-y-1.5 text-brand-moss sm:mt-4">
                                <li>{{ __('Sites: :n', ['n' => $summary['sites']]) }}</li>
                                <li>{{ __('Databases: :n', ['n' => $summary['databases']]) }}</li>
                                <li>{{ __('Cron jobs: :n', ['n' => $summary['cron_jobs']]) }}</li>
                                <li>{{ __('Daemons: :n', ['n' => $summary['supervisor_programs']]) }}</li>
                                <li>{{ __('Firewall rules: :n', ['n' => $summary['firewall_rules']]) }}</li>
                                <li>{{ __('SSH keys (stored): :n', ['n' => $summary['authorized_keys']]) }}</li>
                                <li>{{ __('Recipes: :n', ['n' => $summary['recipes']]) }}</li>
                                <li>{{ __('Running deployments: :n', ['n' => $summary['running_deployments']]) }}</li>
                                <li>{{ __('Provider: :p', ['p' => $summary['provider_label']]) }}</li>
                                @if ($summary['will_destroy_cloud'])
                                    <li class="font-medium text-amber-800">{{ __('Matching cloud resources are targeted for teardown when applicable.') }}</li>
                                @endif
                            </ul>
                        </div>
                    @endif

                    @if (is_array($summary) && $summary['running_deployments'] > 0 && $removeMode === 'now')
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-relaxed text-red-900 sm:p-5">
                            {{ __('This server has running deployments. Finish or cancel them before removing the server.') }}
                        </div>
                    @endif

                    <div class="space-y-2">
                        <x-input-label for="deletionReason" value="{{ __('Reason (optional, audit log)') }}" />
                        <textarea
                            id="deletionReason"
                            wire:model="deletionReason"
                            rows="2"
                            class="mt-0 block w-full rounded-lg border-zinc-200 px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="{{ __('e.g. Decommissioned after migration') }}"
                        ></textarea>
                        <x-input-error :messages="$errors->get('deletionReason')" class="mt-1" />
                    </div>

                    <div class="space-y-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Server name') }}</p>
                        <div class="flex flex-wrap items-center gap-3">
                            <code class="rounded-lg bg-zinc-100 px-3 py-2.5 text-sm font-mono text-brand-ink break-all">{{ $serverName }}</code>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/60"
                                x-data="{ copied: false }"
                                x-on:click="navigator.clipboard.writeText(@js($serverName)).then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                            >
                                <x-heroicon-o-clipboard class="h-4 w-4 shrink-0" />
                                <span x-show="!copied">{{ __('Copy') }}</span>
                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                        <p class="text-xs leading-relaxed text-brand-moss">{{ __('Type or paste the name exactly to confirm.') }}</p>
                    </div>
                    <div class="space-y-2">
                        <x-input-label for="deleteConfirmName" value="{{ __('Confirm name') }}" />
                        <x-text-input id="deleteConfirmName" wire:model="deleteConfirmName" class="mt-0 block w-full py-2.5 font-mono text-sm" autocomplete="off" />
                        <x-input-error :messages="$errors->get('deleteConfirmName')" class="mt-1" />
                    </div>
                    <fieldset class="space-y-4">
                        <legend class="mb-1 block text-sm font-medium text-brand-ink sm:mb-2">{{ __('When') }}</legend>
                        <x-input-error :messages="$errors->get('removeMode')" class="text-sm text-red-600" />
                        <label class="flex cursor-pointer items-start gap-4 rounded-xl border border-zinc-200 p-4 has-[:checked]:border-brand-ink/25 has-[:checked]:bg-brand-sand/20 sm:p-5">
                            <input type="radio" wire:model.live="removeMode" value="now" class="mt-1 shrink-0 text-brand-ink focus:ring-brand-sage" />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-brand-ink">{{ __('Remove now') }}</span>
                                <span class="mt-1.5 block text-xs leading-relaxed text-brand-moss">{{ __('The server record and matching cloud resources are removed after you confirm below.') }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-4 rounded-xl border border-zinc-200 p-4 has-[:checked]:border-brand-ink/25 has-[:checked]:bg-brand-sand/20 sm:p-5">
                            <input type="radio" wire:model.live="removeMode" value="scheduled" class="mt-1 shrink-0 text-brand-ink focus:ring-brand-sage" />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-brand-ink">{{ __('Schedule removal') }}</span>
                                <span class="mt-1.5 block text-xs leading-relaxed text-brand-moss">{{ __('Removal runs automatically at the end of the day you pick (app timezone). You can cancel before then.') }}</span>
                            </span>
                        </label>
                    </fieldset>
                    @if ($removeMode === 'scheduled')
                        <div class="flex flex-col gap-5">
                            <div class="flex flex-wrap gap-2 sm:gap-3">
                                <span class="w-full text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Quick dates') }}</span>
                                <button type="button" wire:click="applyRemovalDatePreset('tomorrow')" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-zinc-50">
                                    {{ __('+1 day') }}
                                </button>
                                <button type="button" wire:click="applyRemovalDatePreset('week')" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-zinc-50">
                                    {{ __('+7 days') }}
                                </button>
                                <button type="button" wire:click="applyRemovalDatePreset('month')" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-zinc-50">
                                    {{ __('+30 days') }}
                                </button>
                            </div>
                            <div class="space-y-2">
                                <x-input-label for="scheduledRemovalDate" value="{{ __('Delete on (end of day)') }}" />
                                <input
                                    id="scheduledRemovalDate"
                                    type="date"
                                    wire:model="scheduledRemovalDate"
                                    class="mt-0 block w-full rounded-lg border-zinc-200 px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                                <x-input-error :messages="$errors->get('scheduledRemovalDate')" class="mt-1" />
                            </div>
                        </div>
                    @endif

                    @if ($removeMode === 'now')
                        <div class="space-y-5 border-t border-zinc-100 pt-6 sm:space-y-6 sm:pt-8">
                            <p class="text-sm font-medium text-brand-ink">{{ __('Immediate removal') }}</p>
                            <div class="space-y-2">
                                <x-input-label for="deletePhraseControl" value="{{ __('Type DELETE to confirm') }}" />
                                <x-text-input id="deletePhraseControl" wire:model="deletePhraseControl" class="mt-0 block w-full py-2.5 font-mono text-sm uppercase" autocomplete="off" />
                                <x-input-error :messages="$errors->get('deletePhraseControl')" class="mt-1" />
                            </div>
                            <div class="space-y-2">
                                <x-input-label for="removeServerCurrentPassword" value="{{ __('Your password') }}" />
                                <x-text-input id="removeServerCurrentPassword" type="password" wire:model="currentPassword" class="mt-0 block w-full py-2.5" autocomplete="current-password" />
                                <x-input-error :messages="$errors->get('currentPassword')" class="mt-1" />
                            </div>
                            @if (is_array($summary) && $summary['will_destroy_cloud'])
                                <label class="flex cursor-pointer items-start gap-4 rounded-xl border border-zinc-200 p-4 has-[:checked]:border-brand-ink/25 has-[:checked]:bg-brand-sand/20 sm:p-5">
                                    <input type="checkbox" wire:model="deleteAckCloud" class="mt-1 shrink-0 rounded border-zinc-300 text-brand-ink focus:ring-brand-sage" />
                                    <span class="text-sm leading-relaxed text-brand-ink">{{ __('I understand linked cloud resources may be destroyed and billing may change at the provider.') }}</span>
                                </label>
                                <x-input-error :messages="$errors->get('deleteAckCloud')" class="mt-1" />
                            @endif
                            @if (is_array($summary) && $summary['sites'] > 0)
                                <label class="flex cursor-pointer items-start gap-4 rounded-xl border border-zinc-200 p-4 has-[:checked]:border-brand-ink/25 has-[:checked]:bg-brand-sand/20 sm:p-5">
                                    <input type="checkbox" wire:model="deleteAckSites" class="mt-1 shrink-0 rounded border-zinc-300 text-brand-ink focus:ring-brand-sage" />
                                    <span class="text-sm leading-relaxed text-brand-ink">{{ __('I understand sites on this server will be removed from Dply with the server.') }}</span>
                                </label>
                                <x-input-error :messages="$errors->get('deleteAckSites')" class="mt-1" />
                            @endif
                        </div>
                    @endif

                    <div class="rounded-xl border border-dashed border-zinc-200 bg-white p-4 text-xs leading-relaxed text-brand-moss sm:p-5">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Checklist') }}</p>
                        <ul class="mt-3 list-inside list-disc space-y-2 sm:mt-4">
                            @if ($sitesUrl)
                                <li><a href="{{ $sitesUrl }}" class="font-medium text-brand-ink underline decoration-brand-ink/30 hover:decoration-brand-ink">{{ __('Review sites on this server') }}</a></li>
                            @endif
                            @if (filled($docsUrl))
                                <li><a href="{{ $docsUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-ink underline decoration-brand-ink/30 hover:decoration-brand-ink">{{ __('Read removal documentation') }}</a></li>
                            @endif
                            <li>{{ __('Back up anything you still need from the machine before removal.') }}</li>
                        </ul>
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-3 border-t border-zinc-100 bg-zinc-50/80 px-6 py-5 sm:flex-row sm:justify-end sm:gap-3 sm:px-8 sm:py-6">
                    <button type="button" wire:click="closeRemoveServerModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-brand-ink hover:bg-zinc-50 sm:px-6">
                        {{ __('Cancel') }}
                    </button>
                    <button type="submit" class="inline-flex justify-center rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-red-700 sm:px-6">
                        {{ $removeMode === 'scheduled' ? __('Schedule removal') : __('Remove server') }}
                    </button>
                </div>
            </form>
            </div>
        </div>
    </div>
@endif
