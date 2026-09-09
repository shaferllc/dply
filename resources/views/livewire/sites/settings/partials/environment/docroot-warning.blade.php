    @if ($envInDocroot)
        <div class="{{ $card }}">
            <x-workspace-panel-head
                icon="heroicon-o-exclamation-triangle"
                :title="__('Env file lives inside the docroot')"
                :note="__(':path is reachable by the webserver. The default deny rule blocks /.env over HTTP, but moving the file outside the docroot is safer if the rule is ever changed or bypassed.', ['path' => $site->effectiveEnvFilePath()])"
                tone="amber"
            >
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="relocateEnvOutsideDocroot"
                        wire:loading.attr="disabled"
                        wire:target="relocateEnvOutsideDocroot"
                        class="dply-btn dply-btn-sm dply-btn-outline"
                        title="{{ __('Move .env to /etc/dply/:slug.env (root:site-user 640) and push.', ['slug' => $site->slug]) }}"
                    >
                        <x-heroicon-o-arrow-up-on-square class="h-4 w-4" />
                        {{ __('Move outside docroot') }}
                    </button>
                </x-slot:actions>
            </x-workspace-panel-head>
        </div>
    @endif
