{{-- Servers & sites: the org defaults block. --}}
            {{-- Organization defaults --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-building-office-2"
                    :title="__('Organization defaults')"
                    :note="__('Email and new-server policy for the current organization.')"
                >
                    @if ($currentOrg)
                        <x-slot:actions>
                            <span class="inline-flex items-center rounded border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-moss" title="{{ $currentOrg->name }}">{{ $currentOrg->name }}</span>
                        </x-slot:actions>
                    @endif
                </x-workspace-panel-head>
                <form wire:submit="saveOrganizationServersSites" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save organization settings') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                            {{ __('Create or join an organization to configure these options.') }}
                        </div>
                    @elseif (! $canEditOrgPrefs)
                        <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                            {{ __('Only organization admins can change organization defaults.') }}
                        </div>
                    @else
                        <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-lg border border-brand-ink/10">
                            <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                <input type="checkbox" wire:model.boolean="organizationServerSite.email_server_passwords" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-brand-ink">{{ __('Receive server passwords via email') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('When off, retrieve credentials from each server\'s settings in the app.') }}</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                <input type="checkbox" wire:model.boolean="organizationServerSite.set_timezone_on_new_servers" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-brand-ink">{{ __('Set timezone on new servers') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Apply the timezone above to new servers. (Currently: :tz)', ['tz' => $userTimezoneLabel]) }}</span>
                                </span>
                            </label>
                        </div>
                    @endif
                </form>
            </div>

