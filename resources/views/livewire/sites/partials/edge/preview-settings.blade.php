@can('update', $site)
    @if (! $edgeIsPreviewChild)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-chat-bubble-left-right class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Comments') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Preview comment widget') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Show a floating "Comments" button on every preview deploy of this site. Anyone visiting the preview URL can leave anonymous review notes that appear in the preview workspace.') }}</p>
                </div>
            </div>
            <form wire:submit.prevent="saveEdgeCommentWidget" class="space-y-3 px-6 py-5 sm:px-8">
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_comment_widget_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('Inject widget on preview deploys') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Worker adds a script tag before </body> on HTML responses for any PR preview of this site. Production traffic is never touched.') }}</span>
                    </span>
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeCommentWidget"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeCommentWidget" />
                    <span wire:loading.remove wire:target="saveEdgeCommentWidget">{{ __('Save widget settings') }}</span>
                    <span wire:loading wire:target="saveEdgeCommentWidget">{{ __('Saving…') }}</span>
                </button>
            </form>
        </section>

        <section id="edge-previews-protection" class="scroll-mt-24 dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Protection') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Preview protection') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Require a password or Dply sign-in before anyone can view PR previews and deploy aliases. Your live production URL and custom domains stay public.') }}</p>
                </div>
            </div>
            <form
                wire:submit.prevent="saveEdgePreviewProtection"
                x-data="{ mode: @entangle('buildForm.edge_preview_protection_mode').defer }"
                class="space-y-5 px-6 py-5 sm:px-8"
            >
                <fieldset class="space-y-3">
                    <legend class="sr-only">{{ __('Preview protection mode') }}</legend>
                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                        <input type="radio" x-model="mode" value="off" class="mt-0.5 border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span>
                            <span class="font-medium">{{ __('Off') }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Anyone with a preview or alias URL can view the deploy.') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                        <input type="radio" x-model="mode" value="password" class="mt-0.5 border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span>
                            <span class="font-medium">{{ __('Shared password') }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Visitors enter one site-wide password at the edge before the preview loads.') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                        <input type="radio" x-model="mode" value="dply_account" class="mt-0.5 border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span>
                            <span class="font-medium">{{ __('Dply account') }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Visitors sign in to Dply; optionally restrict to specific email addresses.') }}</span>
                        </span>
                    </label>
                    @error('buildForm.edge_preview_protection_mode')
                        <p class="text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div x-show="mode === 'password'" x-cloak>
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Preview password') }}</span>
                        <input
                            type="password"
                            wire:model="buildForm.edge_preview_protection_password"
                            autocomplete="new-password"
                            placeholder="{{ __('Leave blank to keep the current password') }}"
                            class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Required when enabling password protection for the first time. Changing the password invalidates existing preview access cookies.') }}</p>
                        @error('buildForm.edge_preview_protection_password')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <div x-show="mode === 'dply_account'" x-cloak>
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed email addresses') }}</span>
                        <textarea
                            wire:model="buildForm.edge_preview_protection_allowed_emails"
                            rows="4"
                            spellcheck="false"
                            placeholder="reviewer@example.com&#10;pm@example.com"
                            class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        ></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Optional. One email per line (commas also work). Leave empty to allow any signed-in Dply user who can view this site.') }}</p>
                        @error('buildForm.edge_preview_protection_allowed_emails')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgePreviewProtection"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgePreviewProtection" />
                    <span wire:loading.remove wire:target="saveEdgePreviewProtection">{{ __('Save preview protection') }}</span>
                    <span wire:loading wire:target="saveEdgePreviewProtection">{{ __('Saving…') }}</span>
                </button>
            </form>
        </section>
    @endif
@endcan