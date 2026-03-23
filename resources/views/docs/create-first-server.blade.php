<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('docs.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">← Docs</a>
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">Create your first server</h2>
        </div>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 prose prose-slate max-w-none">
            <p class="text-slate-600">You can create a server with <strong>DigitalOcean</strong>, <strong>Hetzner</strong>, or connect an <strong>existing server</strong> (custom). If you haven’t added a cloud credential yet, see <a href="{{ route('docs.connect-provider') }}" class="text-slate-900 underline font-medium">Connect a cloud provider</a> first.</p>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">1. Open Add Server</h3>
            <p class="text-slate-700">Go to <strong>Servers</strong> → <strong>Add server</strong>, or use the “Add server” button on the dashboard.</p>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">2. Choose provider and credential</h3>
            <p class="text-slate-700">Pick the tab: <strong>Create with DigitalOcean</strong>, <strong>Create with Hetzner</strong>, or <strong>Connect existing server</strong>. For DO or Hetzner, select the <strong>Account</strong> (credential) you added in Credentials.</p>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">3. Name, region, and size</h3>
            <ul class="list-disc list-inside space-y-1 text-slate-700">
                <li><strong>Server name</strong> — Any label (e.g. “web-1”).</li>
                <li><strong>Region / Location</strong> — Where the server will live (e.g. NYC, FSN).</li>
                <li><strong>Size / Server type</strong> — CPU and RAM (e.g. s-1vcpu-1gb).</li>
            </ul>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">4. Setup script (optional)</h3>
            <p class="text-slate-700">You can choose a <strong>setup script</strong> to run once the server is ready (e.g. install Docker, Node, or a stack). If you don’t need one, leave it as “None”.</p>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">5. Submit</h3>
            <p class="text-slate-700">Click <strong>Add server</strong>. The server is created and appears in your list with status <strong>pending</strong> (then <strong>provisioning</strong>). When the cloud provider has assigned an IP and dply has finished setup, the status becomes <strong>ready</strong>.</p>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">Pending vs ready</h3>
            <ul class="list-disc list-inside space-y-1 text-slate-700">
                <li><strong>Pending / Provisioning</strong> — Server is being created; IP and SSH aren’t available yet.</li>
                <li><strong>Ready</strong> — Server has an IP and is reachable. You can run commands and deploy.</li>
            </ul>

            <h3 class="text-lg font-semibold text-slate-900 mt-8">6. Server detail, Run command, Deploy</h3>
            <p class="text-slate-700">From the server list, open the server name to go to its <strong>detail page</strong>. There you can:</p>
            <ul class="list-disc list-inside space-y-1 text-slate-700">
                <li><strong>Run command</strong> — Run an arbitrary command on the server over SSH.</li>
                <li><strong>Deploy</strong> — Trigger the server’s deploy command (e.g. pull and restart). You can set or edit the deploy command on the same page.</li>
            </ul>
        </div>
    </div>
</x-app-layout>
