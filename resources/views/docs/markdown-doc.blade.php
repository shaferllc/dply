<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-brand-ink leading-tight">{{ $title }}</h2>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <p class="text-sm text-brand-mist mb-6">
                <a href="{{ route('docs.index') }}" class="text-brand-moss hover:text-brand-ink underline transition-colors">← Docs</a>
            </p>
            <div class="rounded-lg border border-brand-ink/10 bg-white p-6 sm:p-8 shadow-sm text-brand-moss text-sm leading-relaxed space-y-4
                [&_h1]:text-2xl [&_h1]:font-semibold [&_h1]:text-brand-ink [&_h1]:mb-4
                [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-brand-ink [&_h2]:mt-8 [&_h2]:mb-3
                [&_h3]:text-base [&_h3]:font-medium [&_h3]:text-brand-ink [&_h3]:mt-6 [&_h3]:mb-2
                [&_p]:mb-3
                [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:space-y-1 [&_ul]:mb-3
                [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:space-y-1 [&_ol]:mb-3
                [&_li]:pl-0.5
                [&_a]:text-brand-forest [&_a]:underline [&_a:hover]:text-brand-sage
                [&_code]:text-xs [&_code]:bg-slate-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:rounded [&_code]:font-mono
                [&_table]:w-full [&_table]:text-sm [&_th]:text-left [&_th]:border-b [&_th]:border-brand-ink/15 [&_th]:py-2 [&_th]:pr-3
                [&_td]:border-b [&_td]:border-brand-ink/10 [&_td]:py-2 [&_td]:pr-3
                [&_strong]:text-brand-ink [&_strong]:font-medium">
                {!! $html !!}
            </div>
        </div>
    </div>
</x-app-layout>
