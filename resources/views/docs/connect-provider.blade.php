<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('docs.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">← Docs</a>
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">Connect a cloud provider</h2>
        </div>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 prose prose-slate max-w-none">
            <p class="text-slate-600">To create servers in dply, you add a cloud provider API token as a <strong>credential</strong>. dply supports <strong>DigitalOcean</strong> and <strong>Hetzner</strong>.</p>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">1. Get an API token</h3>

            <h4 class="text-base font-medium text-slate-800 mt-4">DigitalOcean</h4>
            <ol class="list-decimal list-inside space-y-2 text-slate-700">
                <li>Go to <a href="https://cloud.digitalocean.com/account/api/tokens" target="_blank" rel="noopener" class="text-slate-900 underline">DigitalOcean → Account → API</a>.</li>
                <li>Click <strong>Generate New Token</strong>.</li>
                <li>Name it (e.g. “dply”) and choose Read & Write. Copy the token; you won’t see it again.</li>
            </ol>

            <h4 class="text-base font-medium text-slate-800 mt-4">Hetzner</h4>
            <ol class="list-decimal list-inside space-y-2 text-slate-700">
                <li>Open <a href="https://console.hetzner.cloud/" target="_blank" rel="noopener" class="text-slate-900 underline">Hetzner Cloud Console</a> and select a project.</li>
                <li>Go to <strong>Security</strong> → <strong>API Tokens</strong>.</li>
                <li>Create a token with Read & Write. Copy it; it’s shown only once.</li>
            </ol>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">2. Add the credential in dply</h3>
            <ol class="list-decimal list-inside space-y-2 text-slate-700">
                <li>In dply, open <a href="{{ route('credentials.index') }}" class="text-slate-900 underline font-medium">Credentials</a> (in the main menu).</li>
                <li>Under “Add DigitalOcean” or “Add Hetzner”, paste your API token.</li>
                <li>Give it a <strong>label</strong> (e.g. “Personal” or “Production”) so you can pick it when creating servers.</li>
                <li>Click <strong>Connect</strong>.</li>
            </ol>

            <p class="text-slate-600 mt-6">Once saved, the credential appears in “Your credentials”. You can add more than one (e.g. different DO or Hetzner accounts). When you <a href="{{ route('docs.create-first-server') }}" class="text-slate-900 underline">create a server</a>, you choose which credential to use.</p>
        </div>
    </div>
</x-app-layout>
