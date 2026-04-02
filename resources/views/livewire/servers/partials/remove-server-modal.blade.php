{{--
  Livewire: removeMode
  Methods: closeRemoveServerModal, submitRemoveServer
  Props: $open (bool), $serverName (string), $serverId (string), $deletionSummary (?array)
--}}
@php
    $summary = $deletionSummary ?? null;
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
                        <p class="mt-3 text-sm leading-relaxed text-brand-moss sm:mt-4">
                            {{ __('This removes :name from Dply.', ['name' => $serverName]) }}
                            @if (is_array($summary) && $summary['will_destroy_cloud'])
                                {{ __('Linked cloud resources are also targeted for teardown when available.') }}
                            @endif
                            {{ __('This cannot be undone.') }}
                        </p>
                    </div>
                    <div class="space-y-6 px-6 py-7 sm:px-8 sm:py-8">
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

                    <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm leading-relaxed text-brand-moss sm:p-5">
                        {{ __('Make sure you have anything you still need from this machine before you continue.') }}
                    </div>
                    </div>
                    <div class="flex flex-col-reverse gap-3 border-t border-zinc-100 bg-zinc-50/80 px-6 py-5 sm:flex-row sm:justify-end sm:gap-3 sm:px-8 sm:py-6">
                        <button type="button" wire:click="closeRemoveServerModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-brand-ink hover:bg-zinc-50 sm:px-6">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit" class="inline-flex justify-center rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-red-300 sm:px-6" @disabled(is_array($summary) && $summary['running_deployments'] > 0)>
                            {{ __('Remove server') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
