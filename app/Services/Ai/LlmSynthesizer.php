<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class LlmSynthesizer
{
    public function __construct(
        private readonly AiPromptBuilder $promptBuilder,
    ) {}

    public function isConfigured(): bool
    {
        if (! (bool) config('dply_ai.llm.enabled', false)) {
            return false;
        }

        $apiKey = config('dply_ai.llm.api_key');

        return is_string($apiKey) && $apiKey !== '';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function synthesizeOpsCopilot(array $context): LlmSynthesisResult
    {
        return $this->call($this->promptBuilder->opsCopilotSystem($context));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function synthesizeSharedHost(array $report): LlmSynthesisResult
    {
        return $this->call($this->promptBuilder->sharedHostSystem($report));
    }

    public function synthesizeDocsAsk(
        string $docSlug,
        string $docTitle,
        string $docExcerpt,
        string $routeName,
        string $question,
    ): LlmSynthesisResult {
        $prompt = $this->promptBuilder->docsAskSystem($docSlug, $docTitle, $docExcerpt, $routeName, $question);
        $result = $this->call($prompt);

        return new LlmSynthesisResult(
            narrative: $result->narrative,
            suggestions: $result->suggestions,
            promptTokens: $result->promptTokens,
            completionTokens: $result->completionTokens,
            latencyMs: $result->latencyMs,
            rawContent: $result->rawContent,
            metadata: $result->metadata,
        );
    }

    /**
     * Generic JSON completion for callers that need the raw decoded object plus
     * token usage rather than the narrative/suggestions shape `call()` returns
     * (e.g. the roadmap updater, which applies a structured plan).
     *
     * @return array{data: array<string, mixed>, prompt_tokens: int|null, completion_tokens: int|null, latency_ms: int, raw: string}
     */
    public function completeJson(string $userPrompt, ?string $systemOverride = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('LLM synthesis is not configured.');
        }

        $startedAt = microtime(true);
        $timeout = (int) config('dply_ai.llm.timeout_seconds', 45);
        $model = (string) config('dply_ai.llm.model', 'gpt-4o-mini');
        $maxTokens = (int) config('dply_ai.llm.max_output_tokens', 1200);
        $baseUrl = rtrim((string) config('dply_ai.llm.base_url', 'https://api.openai.com/v1'), '/');
        $apiKey = (string) config('dply_ai.llm.api_key');

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemOverride ?? 'You are a precise assistant for the dply hosting platform. Respond with valid JSON only.',
                    ],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('LLM request failed: '.$response->status().' '.$response->body());
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = $response->json();
        $content = (string) ($payload['choices'][0]['message']['content'] ?? '');

        return [
            'data' => $this->parseJsonContent($content),
            'prompt_tokens' => isset($payload['usage']['prompt_tokens']) ? (int) $payload['usage']['prompt_tokens'] : null,
            'completion_tokens' => isset($payload['usage']['completion_tokens']) ? (int) $payload['usage']['completion_tokens'] : null,
            'latency_ms' => $latencyMs,
            'raw' => $content,
        ];
    }

    private function call(string $userPrompt): LlmSynthesisResult
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('LLM synthesis is not configured.');
        }

        $startedAt = microtime(true);
        $timeout = (int) config('dply_ai.llm.timeout_seconds', 45);
        $model = (string) config('dply_ai.llm.model', 'gpt-4o-mini');
        $maxTokens = (int) config('dply_ai.llm.max_output_tokens', 1200);
        $baseUrl = rtrim((string) config('dply_ai.llm.base_url', 'https://api.openai.com/v1'), '/');
        $apiKey = (string) config('dply_ai.llm.api_key');

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a precise DevOps assistant for the dply hosting platform. Respond with valid JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('LLM request failed: '.$response->status().' '.$response->body());
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = $response->json();
        $content = (string) ($payload['choices'][0]['message']['content'] ?? '');
        $parsed = $this->parseJsonContent($content);

        return new LlmSynthesisResult(
            narrative: (string) ($parsed['narrative'] ?? $parsed['answer'] ?? ''),
            suggestions: $this->normalizeSuggestions($parsed),
            promptTokens: isset($payload['usage']['prompt_tokens']) ? (int) $payload['usage']['prompt_tokens'] : null,
            completionTokens: isset($payload['usage']['completion_tokens']) ? (int) $payload['usage']['completion_tokens'] : null,
            latencyMs: $latencyMs,
            rawContent: $content,
            metadata: [
                'confidence' => is_string($parsed['confidence'] ?? null) ? $parsed['confidence'] : null,
                'cited_headings' => is_array($parsed['cited_headings'] ?? null) ? $parsed['cited_headings'] : [],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonContent(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['narrative' => $content, 'suggestions' => []];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<array{title: string, summary: string, confidence: string, doc_slug: string|null, actions: list<array{label: string, url: string}>}>
     */
    private function normalizeSuggestions(array $parsed): array
    {
        $rows = $parsed['suggestions'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $summary = trim((string) ($row['summary'] ?? ''));
            if ($title === '' || $summary === '') {
                continue;
            }

            $confidence = strtolower(trim((string) ($row['confidence'] ?? 'medium')));
            if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = 'medium';
            }

            $docSlug = $row['doc_slug'] ?? null;
            $actions = [];
            foreach ($row['actions'] ?? [] as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $label = trim((string) ($action['label'] ?? ''));
                $url = trim((string) ($action['url'] ?? ''));
                if ($label === '' || $url === '') {
                    continue;
                }
                $actions[] = ['label' => $label, 'url' => $url];
            }

            $normalized[] = [
                'title' => $title,
                'summary' => $summary,
                'confidence' => $confidence,
                'doc_slug' => is_string($docSlug) && $docSlug !== '' ? $docSlug : null,
                'actions' => $actions,
            ];
        }

        if ($normalized === [] && isset($parsed['answer']) && is_string($parsed['answer']) && trim($parsed['answer']) !== '') {
            $normalized[] = [
                'title' => 'Answer',
                'summary' => trim($parsed['answer']),
                'confidence' => is_string($parsed['confidence'] ?? null) ? (string) $parsed['confidence'] : 'medium',
                'doc_slug' => null,
                'actions' => [],
            ];
        }

        return $normalized;
    }
}
