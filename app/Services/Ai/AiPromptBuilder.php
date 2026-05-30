<?php

declare(strict_types=1);

namespace App\Services\Ai;

final class AiPromptBuilder
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function opsCopilotSystem(array $context): string
    {
        return implode("\n", [
            'You are dply Ops Copilot — an infrastructure advisor for deploy failures on BYO VMs, Edge, and Cloud.',
            'Suggest 1-3 concrete fixes. Never recommend running destructive commands automatically.',
            'Prefer env var fixes, build command changes, and doc-backed steps.',
            'Return JSON only with this shape:',
            '{"narrative":"one paragraph summary","suggestions":[{"title":"short title","summary":"actionable fix","confidence":"high|medium|low","doc_slug":"optional-docs-slug-or-null","actions":[{"label":"button label","url":"https://relative-or-absolute-deep-link"}]}]}',
            'Context JSON:',
            json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function sharedHostSystem(array $report): string
    {
        return implode("\n", [
            'You are dply Shared Host Fairness Advisor — explain multi-site VM contention in plain English.',
            'Cite site slugs and CPU/memory percentages from the report JSON only — do not invent metrics.',
            'Recommend safe next steps: stagger deploys, adjust budgets, promote to standby, review cron/workers.',
            'Never suggest auto-running SSH or changing firewall rules.',
            'Return JSON only:',
            '{"narrative":"2-4 sentences","suggestions":[{"title":"short title","summary":"why and what to do","confidence":"high|medium|low","actions":[{"label":"button label","url":"https://..."}]}]}',
            'Report JSON:',
            json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function docsAskSystem(string $docSlug, string $docTitle, string $docExcerpt, string $routeName, string $question): string
    {
        return implode("\n", [
            'You are dply documentation assistant. Answer using ONLY the provided doc excerpt and page context.',
            'If the excerpt does not contain the answer, say so and suggest opening the full doc page.',
            'Do not invent SSH commands unless they appear verbatim in the excerpt.',
            'Return JSON only:',
            '{"answer":"markdown-safe plain text answer","cited_headings":["heading names referenced"],"confidence":"high|medium|low"}',
            'Doc slug: '.$docSlug,
            'Doc title: '.$docTitle,
            'Current route: '.$routeName,
            'User question: '.$question,
            'Doc excerpt:',
            $docExcerpt,
        ]);
    }
}
