<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('docs.index') }}" class="text-brand-mist hover:text-brand-ink text-sm transition-colors">← Docs</a>
            <h2 class="font-semibold text-xl text-brand-ink leading-tight">Connect a cloud provider</h2>
        </div>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6 text-sm leading-relaxed">
            <p class="text-brand-moss">To create servers in dply, you add a <strong class="text-brand-ink font-semibold">server provider</strong> API token (stored as an encrypted credential). This is separate from <strong class="text-brand-ink font-semibold">Git</strong> (source control). dply supports <strong class="text-brand-ink font-semibold">DigitalOcean</strong> and <strong class="text-brand-ink font-semibold">Hetzner</strong>, among others.</p>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">1. Get an API token</h3>

            <h4 class="text-base font-medium text-brand-ink mt-4">DigitalOcean</h4>
            <ol class="list-decimal list-inside space-y-2 text-brand-moss">
                <li>Go to <a href="https://cloud.digitalocean.com/account/api/tokens" target="_blank" rel="noopener" class="text-brand-forest underline hover:text-brand-sage">DigitalOcean → Account → API</a>.</li>
                <li>Click <strong class="text-brand-ink">Generate New Token</strong>.</li>
                <li>Name it (e.g. “dply”) and choose Read & Write. Copy the token; you won’t see it again.</li>
            </ol>

            <h4 class="text-base font-medium text-brand-ink mt-4">Hetzner</h4>
            <ol class="list-decimal list-inside space-y-2 text-brand-moss">
                <li>Open <a href="https://console.hetzner.cloud/" target="_blank" rel="noopener" class="text-brand-forest underline hover:text-brand-sage">Hetzner Cloud Console</a> and select a project.</li>
                <li>Go to <strong class="text-brand-ink">Security</strong> → <strong class="text-brand-ink">API Tokens</strong>.</li>
                <li>Create a token with Read & Write. Copy it; it’s shown only once.</li>
            </ol>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">2. Add the token in dply</h3>
            <ol class="list-decimal list-inside space-y-2 text-brand-moss">
                <li>In dply, open <a href="{{ route('credentials.index') }}" class="text-brand-forest underline font-medium hover:text-brand-sage">Server providers</a> (settings sidebar under your current organization).</li>
                <li>Under “Add DigitalOcean” or “Add Hetzner”, paste your API token.</li>
                <li>Give it a <strong class="text-brand-ink">label</strong> (e.g. “Personal” or “Production”) so you can pick it when creating servers.</li>
                <li>Click <strong class="text-brand-ink">Connect</strong>.</li>
            </ol>

            <p class="text-brand-moss mt-6">Once saved, it appears in the saved list on that page. You can add more than one (e.g. different DO or Hetzner accounts). When you <a href="{{ route('docs.create-first-server') }}" class="text-brand-forest underline hover:text-brand-sage">create a server</a>, you choose which account to use.</p>
        </div>
    </div>
</x-app-layout>
