@php
    // Gradient + initials fallback for the icon preview (mirrors the site-logo
    // partial) so the preview matches the placeholder shown when no icon is set.
    $iconSeed = (string) ($organization->slug ?: $organization->name ?: $organization->id);
    $iconHash = hexdec(substr(sha1($iconSeed), 0, 12));
    $iconHueA = $iconHash % 360;
    $iconHueB = ($iconHueA + 60 + ((int) (($iconHash >> 4) % 120))) % 360;
    $iconFallbackStyle = "background-image: linear-gradient(135deg, hsl({$iconHueA}deg 65% 56%) 0%, hsl({$iconHueB}deg 65% 42%) 100%);";
    $canDelete = auth()->user()?->can('delete', $organization);
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="general"
            :title="__('General settings')"
            :description="__('Update this organization\'s name, contact email, time zone, and branding. Changes apply across the dashboard for everyone with access.')"
            icon="heroicon-o-cog-6-tooth"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('General'), 'icon' => 'cog-6-tooth'],
            ]"
        >
            <x-slot:actions>
                <x-outline-link href="{{ route('organizations.show', $organization) }}" wire:navigate>
                    <x-heroicon-o-building-office-2 class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Organization overview') }}
                </x-outline-link>
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-3" aria-label="{{ __('Organization settings at a glance') }}">
                    <x-fleet-stat :label="__('Plan')">
                        <p class="mt-2 truncate text-sm font-semibold text-brand-ink" title="{{ $organization->planTierLabel() }}">{{ $organization->planTierLabel() }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Org-wide subscription') }}</p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Members')">
                        <p class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $organization->users->count() }}</span>
                            <span class="text-xs text-brand-moss">{{ trans_choice('member|members', $organization->users->count()) }}</span>
                        </p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('With access') }}</p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Created')">
                        <p class="mt-2 truncate text-sm font-semibold text-brand-ink">{{ $organization->created_at?->format('M j, Y') ?? '—' }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ $organization->created_at?->diffForHumans() ?? '' }}</p>
                    </x-fleet-stat>
                </dl>
            </x-slot:stats>

            @if ($errors->isNotEmpty() || session('settings_status'))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                    @if (session('settings_status'))
                        <div @class([
                            'rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900',
                            'mt-3' => $errors->isNotEmpty(),
                        ])>{{ session('settings_status') }}</div>
                    @endif
                </div>
            @endif

            {{-- Icon / branding --}}
            <section class="border-b border-brand-ink/10">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <x-icon-badge tone="gold">
                        <x-heroicon-o-photo class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Branding') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Organization icon') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Shown beside this organization across the dashboard. PNG, JPG, WEBP, GIF or ICO up to 1 MB.') }}
                        </p>
                    </div>
                </div>

                <div class="px-5 py-5 sm:px-6">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                        <div class="shrink-0">
                            @if ($organization->iconUrl())
                                {{-- onerror: swap to the initials fallback if the stored
                                     icon file is missing — never the broken-image glyph. --}}
                                <img src="{{ $organization->iconUrl() }}" alt="{{ $organization->name }}"
                                    onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='inline-flex';"
                                    class="h-16 w-16 rounded-2xl object-cover ring-1 ring-brand-ink/10 shadow-sm bg-white" />
                                <span class="h-16 w-16 shrink-0 items-center justify-center rounded-2xl text-white text-lg font-semibold shadow-sm ring-1 ring-brand-ink/10" style="display: none; {{ $iconFallbackStyle }}">
                                    {{ $organization->initials() }}
                                </span>
                            @else
                                <span class="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl text-white text-lg font-semibold shadow-sm ring-1 ring-brand-ink/10" style="{{ $iconFallbackStyle }}">
                                    {{ $organization->initials() }}
                                </span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1 space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="org_icon_upload">{{ __('Upload image') }}</span>
                                    <span wire:loading wire:target="org_icon_upload">{{ __('Uploading…') }}</span>
                                    <input type="file" wire:model="org_icon_upload" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon" class="hidden" />
                                </label>

                                @if ($organization->hasIcon())
                                    <button type="button" wire:click="removeOrgIcon" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-moss shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                        {{ __('Remove') }}
                                    </button>
                                @endif
                            </div>
                            <x-input-error :messages="$errors->get('org_icon_upload')" />
                        </div>
                    </div>
                </div>
            </section>

            {{-- General details --}}
            <form wire:submit="saveGeneral" class="border-b border-brand-ink/10">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <x-icon-badge>
                        <x-heroicon-o-identification class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Identity') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('General') }}</h2>
                    </div>
                </div>

                <div class="space-y-5 px-5 py-5 sm:px-6">
                    <div>
                        <x-input-label for="org_name" :value="__('Name')" />
                        <x-text-input id="org_name" wire:model="name" type="text" class="mt-1 block w-full" maxlength="255" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="org_slug" :value="__('Handle')" />
                        <x-text-input id="org_slug" wire:model="slug" type="text" class="mt-1 block w-full" maxlength="255" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Lowercase letters, numbers, dashes. Used for references; URLs use the organization ID.') }}</p>
                        <x-input-error :messages="$errors->get('slug')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="org_email" :value="__('Contact email')" />
                        <x-text-input id="org_email" wire:model="email" type="email" class="mt-1 block w-full" maxlength="255" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="org_description" :value="__('Description')" />
                        <x-textarea id="org_description" wire:model="description" rows="3" class="mt-1 block w-full" maxlength="500" />
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="org_timezone" :value="__('Timezone')" />
                        <x-select id="org_timezone" wire:model="timezone" class="mt-1 block w-full">
                            <option value="">{{ __('— None —') }}</option>
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
                    </div>
                </div>

                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/10 px-5 py-4 sm:px-6">
                    <x-primary-button type="submit">
                        <span wire:loading.remove wire:target="saveGeneral">{{ __('Save changes') }}</span>
                        <span wire:loading wire:target="saveGeneral">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </div>
            </form>

            {{-- Danger zone --}}
            @if ($canDelete)
                <section>
                    <div class="flex items-start gap-3 border-b border-red-200/80 bg-red-50 px-5 py-4 sm:px-6">
                        <x-heroicon-o-exclamation-triangle class="h-6 w-6 shrink-0 text-red-600" aria-hidden="true" />
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-red-700">{{ __('Danger zone') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-red-900">{{ __('Delete organization') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-red-800/90">
                                {{ __('Permanently delete this organization and its settings. Remove all servers and sites and cancel any subscription first. This cannot be undone.') }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-3 bg-red-50/40 px-5 py-5 sm:px-6">
                        <div>
                            <x-input-label for="delete_confirm" :value="__('Type the organization name to confirm')" />
                            <x-text-input id="delete_confirm" wire:model.live="delete_confirm" type="text" class="mt-1 block w-full" placeholder="{{ $organization->name }}" autocomplete="off" />
                            <x-input-error :messages="$errors->get('delete_confirm')" class="mt-2" />
                        </div>
                        <div class="flex justify-end">
                            <button
                                type="button"
                                x-on:click="$dispatch('open-modal', 'delete-organization-confirmation')"
                                wire:loading.attr="disabled"
                                wire:target="deleteOrganization"
                                @disabled($delete_confirm !== $organization->name)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                                {{ __('Delete organization') }}
                            </button>
                        </div>
                    </div>
                </section>
            @endif
        </x-organization-shell>
    </div>

    @if ($canDelete)
        <x-modal
            name="delete-organization-confirmation"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <div>
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 ring-1 ring-red-200">
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Danger zone') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Delete this organization?') }}</h2>
                    </div>
                </div>
                <div class="space-y-3 px-6 py-6 text-sm leading-6 text-brand-moss">
                    <p>{{ __('This permanently deletes :name and its settings. Servers, sites, and any active subscription must already be cleared. This cannot be undone.', ['name' => $organization->name]) }}</p>
                </div>
                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'delete-organization-confirmation')">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-danger-button
                        type="button"
                        wire:click="deleteOrganization"
                        wire:loading.attr="disabled"
                        wire:target="deleteOrganization"
                        @disabled($delete_confirm !== $organization->name)
                    >
                        <span wire:loading.remove wire:target="deleteOrganization">{{ __('Delete organization') }}</span>
                        <span wire:loading wire:target="deleteOrganization" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Deleting…') }}
                        </span>
                    </x-danger-button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
