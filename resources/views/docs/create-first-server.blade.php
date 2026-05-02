<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('docs.index') }}" class="text-brand-mist hover:text-brand-ink text-sm transition-colors">← Docs</a>
            <h2 class="font-semibold text-xl text-brand-ink leading-tight">Create your first server</h2>
        </div>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6 text-sm leading-relaxed">
            <p class="text-brand-moss">You can create a server with <strong class="text-brand-ink font-semibold">DigitalOcean</strong>, <strong class="text-brand-ink font-semibold">Hetzner</strong>, or connect an <strong class="text-brand-ink font-semibold">existing server</strong> (custom). If you haven’t added a cloud credential yet, see <a href="{{ route('docs.connect-provider') }}" class="text-brand-forest underline font-medium hover:text-brand-sage">Connect a cloud provider</a> first.</p>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">1. Open Add Server</h3>
            <p class="text-brand-moss">Go to <strong class="text-brand-ink">Servers</strong> → <strong class="text-brand-ink">Add server</strong>, or use the “Add server” button on the dashboard.</p>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">2. Choose provider and credential</h3>
            <p class="text-brand-moss">Pick the tab: <strong class="text-brand-ink">Create with DigitalOcean</strong>, <strong class="text-brand-ink">Create with Hetzner</strong>, or <strong class="text-brand-ink">Connect existing server</strong>. For DO or Hetzner, select the <strong class="text-brand-ink">Account</strong> (credential) you added under Server providers.</p>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">3. Name, region, and size</h3>
            <ul class="list-disc list-inside space-y-1 text-brand-moss">
                <li><strong class="text-brand-ink">Server name</strong> — Any label (e.g. “web-1”).</li>
                <li><strong class="text-brand-ink">Region / Location</strong> — Where the server will live (e.g. NYC, FSN).</li>
                <li><strong class="text-brand-ink">Size / Server type</strong> — CPU and RAM (e.g. s-1vcpu-1gb).</li>
            </ul>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">4. Setup script (optional)</h3>
            <p class="text-brand-moss">You can choose a <strong class="text-brand-ink">setup script</strong> to run once the server is ready (e.g. install Docker, Node, or a stack). If you don’t need one, leave it as “None”.</p>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">5. Submit</h3>
            <p class="text-brand-moss">Click <strong class="text-brand-ink">Add server</strong>. The server is created and appears in your list with status <strong class="text-brand-ink">pending</strong> (then <strong class="text-brand-ink">provisioning</strong>). When the cloud provider has assigned an IP and dply has finished setup, the status becomes <strong class="text-brand-ink">ready</strong>.</p>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">Pending vs ready</h3>
            <ul class="list-disc list-inside space-y-1 text-brand-moss">
                <li><strong class="text-brand-ink">Pending / Provisioning</strong> — Server is being created; IP and SSH aren’t available yet.</li>
                <li><strong class="text-brand-ink">Ready</strong> — Server has an IP and is reachable. You can run commands and deploy.</li>
            </ul>

            <h3 class="text-lg font-semibold text-brand-ink mt-8">6. Server detail, Run command, Deploy</h3>
            <p class="text-brand-moss">From the server list, open the server name to go to its <strong class="text-brand-ink">detail page</strong>. There you can:</p>
            <ul class="list-disc list-inside space-y-1 text-brand-moss">
                <li><strong class="text-brand-ink">Run command</strong> — Run an arbitrary command on the server over SSH.</li>
                <li><strong class="text-brand-ink">Deploy</strong> — Trigger the server’s deploy command (e.g. pull and restart). You can set or edit the deploy command on the same page.</li>
            </ul>
        </div>
    </div>
</x-app-layout>
