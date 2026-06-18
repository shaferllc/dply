@props(['content' => ''])

{{-- Renders user-supplied Markdown. Raw HTML is ESCAPED and unsafe links are
     stripped (CommonMark options) — never pass trusted-but-unescaped HTML here.
     Typographic styling is applied via Tailwind arbitrary child selectors so no
     global stylesheet / typography plugin is required. --}}
<div {{ $attributes->merge(['class' => 'text-sm leading-relaxed text-brand-ink break-words [&_p]:my-2 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0 [&_a]:font-medium [&_a]:text-brand-sage [&_a]:underline [&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:my-0.5 [&_h1]:mt-3 [&_h1]:mb-1 [&_h1]:text-base [&_h1]:font-semibold [&_h2]:mt-3 [&_h2]:mb-1 [&_h2]:text-sm [&_h2]:font-semibold [&_h3]:mt-2 [&_h3]:font-semibold [&_code]:rounded [&_code]:bg-brand-sand/50 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.85em] [&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-brand-ink [&_pre]:p-3 [&_pre]:text-xs [&_pre]:text-brand-sand [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_blockquote]:my-2 [&_blockquote]:border-l-2 [&_blockquote]:border-brand-sage/40 [&_blockquote]:pl-3 [&_blockquote]:text-brand-moss [&_strong]:font-semibold [&_hr]:my-3 [&_hr]:border-brand-ink/10 [&_table]:my-2 [&_table]:text-xs [&_th]:border [&_th]:border-brand-ink/10 [&_th]:px-2 [&_th]:py-1 [&_td]:border [&_td]:border-brand-ink/10 [&_td]:px-2 [&_td]:py-1']) }}>
    {!! \Illuminate\Support\Str::markdown($content ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
</div>
