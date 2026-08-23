{{-- Profile hub: the danger block. Extracted so the page body can be
     laid out differently without four copies of the controls. --}}
            {{-- Danger zone --}}
            <div>
                <x-workspace-panel-head
                    dense
                    tone="danger"
                    icon="heroicon-o-trash"
                    :title="__('Delete account')"
                    :note="__('Signs you out and drops access to organizations and data tied to this login. Cannot be undone.')"
                >
                    <x-slot:actions>
                        <a
                            href="{{ route('profile.delete-account') }}"
                            wire:navigate
                            class="inline-flex h-6 items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-700 shadow-sm transition hover:bg-red-100"
                        >
                            <x-heroicon-o-arrow-right-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Delete account') }}
                        </a>
                    </x-slot:actions>
                </x-workspace-panel-head>
            </div>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your profile information.')"
                saveAction="updateProfile"
                discardAction="discardProfileFormUnsaved"
                :targets="$profileFormUnsavedTargets"
                :saveLabel="__('Save profile')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your profile preferences.')"
                saveAction="saveProfile"
                discardAction="discardProfileUnsaved"
                :targets="$profileUnsavedTargets"
                :saveLabel="__('Save settings')"
            />
