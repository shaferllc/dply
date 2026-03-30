<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\WebhookHeaders;
use App\Actions\Attributes\WebhookMethod;
use App\Actions\Attributes\WebhookUrl;
use App\Actions\Decorators\WebhookDecorator;
use App\Actions\DesignPatterns\WebhookDesignPattern;

/**
 * Sends webhooks when actions complete.
 *
 * Uses the decorator pattern to automatically wrap actions and send webhooks
 * after execution completes. The WebhookDecorator intercepts handle() calls
 * and sends webhooks automatically.
 *
 * How it works:
 * 1. When an action uses AsWebhook, WebhookDesignPattern recognizes it
 * 2. ActionManager wraps the action with WebhookDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Sends the webhook with the result
 *    - Returns the result
 *
 * @example
 * // ============================================
 * // Example 1: Minimal Setup (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\WebhookUrl;
 * use App\Actions\Attributes\WebhookMethod;
 * use App\Actions\Attributes\WebhookHeaders;
 *
 * #[WebhookUrl('https://api.example.com/webhooks/order-created')]
 * #[WebhookMethod('post')]
 * class OrderCreated extends Actions
 * {
 *     use AsWebhook;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Create order logic
 *         return $order;
 *     }
 * }
 *
 * // Usage - webhook sent automatically:
 * OrderCreated::run($order);
 * @example
 * // ============================================
 * // Example 2: Full Configuration (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\WebhookUrl;
 * use App\Actions\Attributes\WebhookMethod;
 * use App\Actions\Attributes\WebhookHeaders;
 *
 * #[WebhookUrl('https://api.example.com/webhooks/order-created')]
 * #[WebhookMethod('post')]
 * #[WebhookHeaders([
 *     'Content-Type' => 'application/json',
 *     'Authorization' => 'Bearer ' . config('services.webhook.token'),
 * ])]
 * class OrderCreated extends Actions
 * {
 *     use AsWebhook;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Create order logic
 *         return $order;
 *     }
 *
 *     // Customize payload (always a method, can't use attributes)
 *     public function getWebhookPayload($result, array $arguments): array
 *     {
 *         return [
 *             'order_id' => $result->id,
 *             'amount' => $result->total,
 *             'customer_id' => $result->customer_id,
 *             'timestamp' => now()->toIso8601String(),
 *         ];
 *     }
 *
 *     // Handle webhook success
 *     public function onWebhookSuccess(\Illuminate\Http\Client\Response $response, array $payload): void
 *     {
 *         \Log::info('Webhook sent successfully', [
 *             'status' => $response->status(),
 *             'order_id' => $payload['order_id'] ?? null,
 *         ]);
 *     }
 *
 *     // Handle webhook failures
 *     public function onWebhookFailure(\Throwable $e, array $payload): void
 *     {
 *         \Log::error('Webhook failed', [
 *             'error' => $e->getMessage(),
 *             'payload' => $payload,
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Using Methods Instead of Attributes
 * // ============================================
 * class OrderCreated extends Actions
 * {
 *     use AsWebhook;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Create order logic
 *         return $order;
 *     }
 *
 *     // Define webhook URL via method
 *     public function getWebhookUrl(): string
 *     {
 *         // Can be dynamic based on environment or config
 *         return config('services.webhook.url') . '/order-created';
 *     }
 *
 *     // Define HTTP method (default: 'post')
 *     public function getWebhookMethod(): string
 *     {
 *         return 'post';
 *     }
 *
 *     // Define headers
 *     public function getWebhookHeaders(): array
 *     {
 *         return [
 *             'Content-Type' => 'application/json',
 *             'Authorization' => 'Bearer ' . config('services.webhook.token'),
 *         ];
 *     }
 *
 *     // Customize payload
 *     public function getWebhookPayload($result, array $arguments): array
 *     {
 *         return [
 *             'order_id' => $result->id,
 *             'amount' => $result->total,
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Real-World Usage (from Tags\Actions\Create)
 * // ============================================
 * use App\Actions\Attributes\WebhookUrl;
 * use App\Actions\Attributes\WebhookMethod;
 * use App\Actions\Attributes\WebhookHeaders;
 *
 * #[WebhookUrl('https://api.example.com/webhooks/tag-created')]
 * #[WebhookMethod('post')]
 * #[WebhookHeaders([
 *     'Content-Type' => 'application/json',
 *     'User-Agent' => 'Laravel-Actions/1.0',
 * ])]
 * class CreateTag extends Actions
 * {
 *     use AsWebhook;
 *
 *     public function handle(Team $team, array $data): Tag
 *     {
 *         $tag = Tag::create($data);
 *         $team->tags()->attach($tag);
 *         return $tag;
 *     }
 *
 *     public function getWebhookPayload($result, array $arguments): array
 *     {
 *         return [
 *             'tag_id' => $result->id,
 *             'tag_name' => $result->name,
 *             'team_id' => $arguments[0]->id ?? null, // Team from first argument
 *         ];
 *     }
 *
 *     public function onWebhookSuccess(\Illuminate\Http\Client\Response $response, array $payload): void
 *     {
 *         \Log::info('Tag webhook sent', [
 *             'status' => $response->status(),
 *             'tag_id' => $payload['tag_id'] ?? null,
 *         ]);
 *     }
 *
 *     public function onWebhookFailure(\Throwable $e, array $payload): void
 *     {
 *         \Log::error('Tag webhook failed', [
 *             'payload' => $payload,
 *             'exception' => $e->getMessage(),
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 * // Webhook automatically sent to https://api.example.com/webhooks/tag-created
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // If no webhook URL is provided (via attribute or method), the webhook is skipped.
 * // Default payload includes:
 * // - 'action': The action class name
 * // - 'result': The return value from handle()
 * // - 'timestamp': ISO 8601 timestamp
 * //
 * // Default headers:
 * // - 'Content-Type': 'application/json'
 * // - 'User-Agent': 'Laravel-Actions/1.0'
 * //
 * // Default HTTP method: 'post'
 *
 * @see WebhookDecorator
 * @see WebhookDesignPattern
 * @see WebhookUrl
 * @see WebhookMethod
 * @see WebhookHeaders
 */
trait AsWebhook
{
    //
}
