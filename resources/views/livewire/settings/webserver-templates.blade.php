<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" :section="$orgShellSection ?? 'webserver'">
            <x-livewire-validation-errors />

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Webserver templates'), 'icon' => 'server'],
            ]" />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Webserver templates') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Optional Nginx :code blocks for custom vhosts. Placeholders are replaced when you apply a template to a site (future) or when testing.', ['code' => 'server']) }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                            <a
                                href="{{ route('docs.index') }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Documentation') }}
                            </a>
                            <x-badge tone="accent" :caps="false" class="text-xs">
                                {{ __('Organization: :name', ['name' => $organization->name]) }}
                            </x-badge>
                        </div>
                    </div>
                </div>

                @if ($testMessage)
                    <div
                        @class([
                            'rounded-xl border px-4 py-3 text-sm',
                            'border-green-200 bg-green-50 text-green-900' => $testOk,
                            'border-red-200 bg-red-50 text-red-900' => ! $testOk,
                        ])
                        role="status"
                    >
                        <p class="font-medium">{{ $testOk ? __('Syntax check') : __('Syntax check failed') }}</p>
                        <pre class="mt-2 whitespace-pre-wrap font-mono text-xs opacity-90">{{ $testMessage }}</pre>
                    </div>
                @endif

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4 min-w-0">
                            <h2 class="text-lg font-semibold text-brand-ink">
                                {{ $editingId ? __('Edit webserver template') : __('Create webserver template') }}
                            </h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Use placeholders in the template body. Applying templates to live sites will be wired from the site screen.') }}
                            </p>

                            <div class="mt-8 border-t border-brand-ink/10 pt-6">
                                <p class="text-sm font-medium text-brand-ink">{{ __('Variables') }}</p>
                                <ul class="mt-3 space-y-2 text-sm text-brand-moss">
                                    @foreach ($placeholders as $key => $description)
                                        <li class="leading-relaxed">
                                            <code class="rounded bg-brand-sand/80 px-1.5 py-0.5 font-mono text-xs text-brand-ink">{{ '{'.$key.'}' }}</code>
                                            — {{ __($description) }}
                                        </li>
                                    @endforeach
                                </ul>
                                @php $banner = config('webserver_templates.required_banner_line'); @endphp
                                @if ($banner)
                                    <p class="mt-4 text-xs text-brand-mist">{{ __('Required first line: :line', ['line' => $banner]) }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="lg:col-span-8 space-y-4 min-w-0">
                            @if (! $canManage)
                                <p class="text-sm text-brand-moss">{{ __('Only organization admins can create or edit templates.') }}</p>
                            @else
                                <div>
                                    <label for="tpl-label" class="block text-sm font-medium text-brand-ink">{{ __('Label') }}</label>
                                    <input
                                        id="tpl-label"
                                        type="text"
                                        wire:model="label"
                                        class="mt-2 block w-full rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        placeholder="{{ __('Enter a label to recognize your template') }}"
                                    />
                                    @error('label') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="tpl-content" class="block text-sm font-medium text-brand-ink">{{ __('Template content') }}</label>
                                    <textarea
                                        id="tpl-content"
                                        wire:model="content"
                                        rows="18"
                                        class="mt-2 block w-full rounded-xl border border-brand-mist bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        spellcheck="false"
                                    ></textarea>
                                    @error('content') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        wire:click="testDraft"
                                        wire:loading.attr="disabled"
                                        wire:target="testDraft"
                                        class="inline-flex min-w-[8rem] items-center justify-center gap-2 rounded-xl border border-brand-mist bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="testDraft" class="inline-flex items-center gap-2">{{ __('Test template') }}</span>
                                        <span wire:loading wire:target="testDraft" class="inline-flex items-center gap-2">
                                            <x-spinner variant="forest" size="sm" />
                                            {{ __('Testing…') }}
                                        </span>
                                    </button>
                                    @if ($editingId)
                                        <button type="button" wire:click="cancelEdit" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                                            {{ __('Cancel edit') }}
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="save"
                                        wire:loading.attr="disabled"
                                        wire:target="save"
                                        class="inline-flex min-w-[8rem] items-center justify-center gap-2 rounded-xl border border-transparent bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="save">{{ $editingId ? __('Save changes') : __('Create') }}</span>
                                        <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                                            <x-spinner variant="cream" size="sm" />
                                            {{ __('Saving…') }}
                                        </span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Saved templates') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Reuse these snippets when configuring sites in your organization.') }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 min-w-0">
                            @if ($templates->isEmpty())
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-6 py-12 text-center text-sm text-brand-moss">
                                    {{ __('No templates yet.') }}
                                </div>
                            @else
                                <ul class="space-y-3">
                                    @foreach ($templates as $template)
                                        <li class="rounded-xl border border-brand-mist bg-white p-4 shadow-sm">
                                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="min-w-0">
                                                    <h3 class="font-medium text-brand-ink">{{ $template->label }}</h3>
                                                    <p class="mt-1 text-xs text-brand-moss">
                                                        {{ __('Created :time', ['time' => $template->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s')]) }}
                                                    </p>
                                                </div>
                                                <div class="flex flex-wrap gap-2 shrink-0">
                                                    @if ($canManage)
                                                        <button
                                                            type="button"
                                                            wire:click="startEdit({{ $template->id }})"
                                                            class="inline-flex items-center rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                                        >
                                                            {{ __('Edit') }}
                                                        </button>
                                                    @endif
                                                    <button
                                                        type="button"
                                                        wire:click="testSaved({{ $template->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="testSaved"
                                                        class="inline-flex min-w-[7rem] items-center justify-center gap-2 rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                                    >
                                                        <span wire:loading.remove wire:target="testSaved">{{ __('Test template') }}</span>
                                                        <span wire:loading wire:target="testSaved" class="inline-flex items-center gap-2">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Testing…') }}
                                                        </span>
                                                    </button>
                                                    @if ($canManage)
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('delete', [{{ $template->id }}], @js(__('Delete template')), @js(__('Delete this template?')), @js(__('Delete')), true)"
                                                            class="inline-flex items-center gap-1 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-800 hover:bg-red-100"
                                                        >
                                                            <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                                            {{ __('Delete') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
