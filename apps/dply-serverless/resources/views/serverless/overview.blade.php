<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-zinc-50 p-8 text-zinc-900 antialiased">
    <main class="mx-auto max-w-xl rounded-lg border border-zinc-200 bg-white p-8 shadow-sm">
        <h1 class="text-2xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
        <p class="mt-2 text-sm text-zinc-600">
            Control plane for multi-provider FaaS deploys (spike). Roadmap drivers <code class="rounded bg-zinc-100 px-1">azure</code>,
            <code class="rounded bg-zinc-100 px-1">gcp</code>, <code class="rounded bg-zinc-100 px-1">cloudflare</code>,
            <code class="rounded bg-zinc-100 px-1">netlify</code>, <code class="rounded bg-zinc-100 px-1">vercel</code> are stubs only (no cloud APIs). No secrets are shown here.
        </p>
        <dl class="mt-6 space-y-3 text-sm">
            <div class="flex justify-between gap-4 border-t border-zinc-100 pt-3">
                <dt class="text-zinc-500">Provisioner</dt>
                <dd><code class="rounded bg-zinc-100 px-1.5 py-0.5 text-zinc-800">{{ config('serverless.provisioner') }}</code></dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-zinc-100 pt-3">
                <dt class="text-zinc-500">AWS real SDK</dt>
                <dd>{{ config('serverless.aws.use_real_sdk') ? 'yes' : 'no' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-zinc-100 pt-3">
                <dt class="text-zinc-500">S3 artifact buckets (allow list)</dt>
                <dd>{{ count(config('serverless.aws.s3_allow_buckets', [])) }} configured</dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-zinc-100 pt-3">
                <dt class="text-zinc-500">Cloudflare Workers API</dt>
                <dd>{{ config('serverless.cloudflare.use_real_api') ? 'enabled (if creds + prefix set)' : 'stub' }}</dd>
            </div>
        </dl>
        <p class="mt-6 text-xs text-zinc-500">
            Platform plan (monorepo): <code class="rounded bg-zinc-100 px-1 py-0.5">docs/MULTI_PRODUCT_PLATFORM_PLAN.md</code>
            · App readme: <code class="rounded bg-zinc-100 px-1 py-0.5">apps/dply-serverless/README.md</code>
        </p>
        <p class="mt-4 text-xs text-zinc-500">
            <a href="{{ url('/') }}" class="text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-800">Home</a>
        </p>
    </main>
</body>
</html>
