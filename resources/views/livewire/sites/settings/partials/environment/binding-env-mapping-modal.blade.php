    {{-- Environment mapping — rename-by-adding for one resource binding.
         dply injects Laravel's names (DB_HOST, DATABASE_URL); other stacks read
         the same connection under different ones (Payload: DATABASE_URI,
         Neon/Vercel: POSTGRES_URL, psql tooling: PGHOST/PGUSER/…). Aliases ADD
         the extra name and keep the canonical one, so nothing already reading
         it breaks. Overrides replace the value for a key this resource owns —
         the per-key escape hatch, applied inside the binding layer because that
         layer wins over the editable .env. --}}
    @if (method_exists($this, 'openEnvMapping'))
    <x-modal name="binding-env-mapping-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
        <div class="px-6 py-5">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Environment mapping') }}</h3>
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('Add extra variable names that carry the same value, for stacks that read a different name. The original name is always kept. You can also replace a value for this resource.') }}
            </p>

            @if ($envMappingPending)
                <div class="mt-4 rounded-lg border border-amber-200/70 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    {{ __('This resource is still being provisioned. You can set the mapping now — values appear once it is ready, and the first deploy will already be correct.') }}
                </div>
            @endif

            @if ($envMappingRows === [])
                <p class="mt-4 text-sm text-brand-moss">{{ __('This resource has no variables to map yet.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left">
                        <thead>
                            <tr class="border-b border-brand-ink/10">
                                <th class="w-1/3 pb-2 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Variable') }}</th>
                                <th class="w-1/3 pb-2 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Also inject as') }}</th>
                                <th class="w-1/3 pb-2 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Override value') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8">
                            @foreach ($envMappingRows as $emKey => $emValue)
                                @php $emSecret = in_array($emKey, $envMappingSensitive, true); @endphp
                                <tr wire:key="envmap-{{ md5($emKey) }}">
                                    <td class="py-2.5 pr-3 align-top">
                                        <p class="font-mono text-xs font-semibold text-brand-ink">{{ $emKey }}</p>
                                        <p class="mt-0.5 truncate font-mono text-2xs text-brand-moss" title="{{ $emSecret ? '' : $emValue }}">
                                            @if ($emValue === '')
                                                <span class="italic">{{ __('not set yet') }}</span>
                                            @elseif ($emSecret)
                                                {{ str_repeat('•', 8) }} <x-heroicon-m-lock-closed class="inline h-3 w-3 align-text-bottom" />
                                            @else
                                                {{ Str::limit($emValue, 32) }}
                                            @endif
                                        </p>
                                    </td>
                                    <td class="py-2.5 pr-3 align-top">
                                        <input
                                            type="text"
                                            wire:model.blur="envMappingAliases.{{ $emKey }}"
                                            placeholder="{{ __('e.g. DATABASE_URI, POSTGRES_URL') }}"
                                            class="block w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30"
                                        />
                                        <x-input-error :messages="$errors->get('envMappingAliases.' . $emKey)" class="mt-1" />
                                    </td>
                                    <td class="py-2.5 align-top">
                                        <input
                                            type="{{ $emSecret ? 'password' : 'text' }}"
                                            wire:model.blur="envMappingOverrides.{{ $emKey }}"
                                            autocomplete="off"
                                            placeholder="{{ __('leave blank to use the managed value') }}"
                                            class="block w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30"
                                        />
                                        <x-input-error :messages="$errors->get('envMappingOverrides.' . $emKey)" class="mt-1" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-2xs text-brand-moss">
                    {{ __('Separate multiple names with a comma. Saving does not push anything — redeploy, or use Push to server, to apply.') }}
                </p>
            @endif

            <div class="mt-4 flex items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                <x-secondary-button type="button" wire:click="closeEnvMapping">{{ __('Cancel') }}</x-secondary-button>
                @if ($envMappingRows !== [])
                    <x-primary-button type="button" wire:click="saveEnvMapping" wire:loading.attr="disabled" wire:target="saveEnvMapping">
                        {{ __('Save mapping') }}
                    </x-primary-button>
                @endif
            </div>
        </div>
    </x-modal>
    @endif
