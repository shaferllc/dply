<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\WatermarkEnabled;
use App\Actions\Attributes\WatermarkMode;
use App\Actions\Decorators\WatermarkDecorator;
use App\Actions\DesignPatterns\WatermarkDesignPattern;

/**
 * Adds watermarks/metadata to action results for tracking and auditing.
 *
 * Uses the decorator pattern to automatically wrap actions and apply watermarks
 * to results. The WatermarkDecorator intercepts handle() calls and applies
 * watermark metadata automatically.
 *
 * How it works:
 * 1. When an action uses AsWatermarked, WatermarkDesignPattern recognizes it
 * 2. ActionManager wraps the action with WatermarkDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Applies watermark metadata to the result
 *    - Returns the watermarked result
 *
 * @example
 * // ============================================
 * // Example 1: Minimal Setup (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\WatermarkMode;
 * use App\Actions\Attributes\WatermarkEnabled;
 *
 * #[WatermarkMode('append')]
 * #[WatermarkEnabled(true)]
 * class GenerateReport extends Actions
 * {
 *     use AsWatermarked;
 *
 *     public function handle(User $user): array
 *     {
 *         return ['data' => 'report content'];
 *     }
 * }
 *
 * // Usage - watermark applied automatically:
 * $result = GenerateReport::run($user);
 * // $result = [
 * //     'data' => 'report content',
 * //     '_watermark' => [
 * //         'action' => 'App\Actions\GenerateReport',
 * //         'executed_at' => '2024-01-15T10:30:00Z',
 * //         'executed_by' => 123,
 * //         'request_id' => 'abc-123',
 * //         ...
 * //     ]
 * // ]
 * @example
 * // ============================================
 * // Example 2: Full Configuration (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\WatermarkMode;
 * use App\Actions\Attributes\WatermarkEnabled;
 *
 * #[WatermarkMode('append')]
 * #[WatermarkEnabled(true)]
 * class GenerateReport extends Actions
 * {
 *     use AsWatermarked;
 *
 *     public function handle(User $user): array
 *     {
 *         return ['data' => 'report content'];
 *     }
 *
 *     // Customize watermark data (merges with defaults)
 *     public function getWatermarkData(): array
 *     {
 *         return [
 *             'generated_by' => auth()->id(),
 *             'version' => '1.0',
 *             'environment' => app()->environment(),
 *             'custom_field' => 'custom_value',
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Using Methods Instead of Attributes
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsWatermarked;
 *
 *     public function handle(User $user): array
 *     {
 *         return ['data' => 'report content'];
 *     }
 *
 *     // Control watermark mode: 'append' or 'wrap'
 *     public function getWatermarkMode(): string
 *     {
 *         return 'append'; // or 'wrap'
 *     }
 *
 *     // Enable/disable watermarking dynamically
 *     public function shouldApplyWatermark(): bool
 *     {
 *         // Disable in testing environment
 *         return ! app()->environment('testing');
 *     }
 *
 *     // Customize watermark data
 *     public function getWatermarkData(): array
 *     {
 *         return [
 *             'custom_field' => 'custom_value',
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Append Mode vs Wrap Mode
 * // ============================================
 * // APPEND MODE (default) - modifies result in place
 * #[WatermarkMode('append')]
 * class CreateOrder extends Actions
 * {
 *     use AsWatermarked;
 *
 *     public function handle(Order $order): Order
 *     {
 *         return $order;
 *     }
 * }
 *
 * $order = CreateOrder::run($orderData);
 * // $order is still an Order object with _watermark property
 * // $order->_watermark contains metadata
 *
 * // WRAP MODE - wraps result in new structure
 * #[WatermarkMode('wrap')]
 * class CreateOrder extends Actions
 * {
 *     use AsWatermarked;
 *
 *     public function handle(Order $order): Order
 *     {
 *         return $order;
 *     }
 * }
 *
 * $result = CreateOrder::run($orderData);
 * // $result = [
 * //     'data' => Order object,
 * //     '_watermark' => [...]
 * // ]
 * @example
 * // ============================================
 * // Example 5: Real-World Usage (from Tags\Actions\Create)
 * // ============================================
 * use App\Actions\Attributes\WatermarkMode;
 * use App\Actions\Attributes\WatermarkEnabled;
 *
 * #[WatermarkMode('append')]
 * #[WatermarkEnabled(true)]
 * class CreateTag extends Actions
 * {
 *     use AsWatermarked;
 *
 *     public function handle(Team $team, array $data): Tag
 *     {
 *         $tag = Tag::create($data);
 *         $team->tags()->attach($tag);
 *         return $tag;
 *     }
 *
 *     // Customize watermark with additional context
 *     public function getWatermarkData(): array
 *     {
 *         // Merges with defaults from WatermarkDecorator
 *         // Defaults include: action, executed_at, executed_by, request_id, etc.
 *         return [
 *             'custom_field' => 'custom_value',
 *             'environment' => app()->environment(),
 *             'team_id' => request()->user()?->currentTeam?->id,
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 * // $tag is a Tag model with _watermark property containing metadata
 * // Access watermark: $tag->_watermark
 *
 * // Remove watermark if needed:
 * $cleanTag = \App\Actions\Decorators\WatermarkDecorator::removeWatermark($tag);
 * @example
 * // ============================================
 * // Example 6: Disabling Watermarking
 * // ============================================
 * // Option 1: Using attribute
 * #[WatermarkEnabled(false)]
 * class GenerateReport extends Actions
 * {
 *     use AsWatermarked;
 *     // Watermarking disabled
 * }
 *
 * // Option 2: Using method
 * class GenerateReport extends Actions
 * {
 *     use AsWatermarked;
 *
 *     public function shouldApplyWatermark(): bool
 *     {
 *         return false; // Disable watermarking
 *     }
 * }
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // Default watermark mode: 'append'
 * // Default enabled: true
 * //
 * // Default watermark data includes:
 * // - 'action': The action class name
 * // - 'executed_at': ISO 8601 timestamp
 * // - 'executed_by': Authenticated user ID (if available)
 * // - 'request_id': X-Request-ID header value (if available)
 * // - 'ip_address': Client IP address
 * // - 'user_agent': Client user agent
 * // - 'route': Route name (if available)
 * // - 'method': HTTP method
 * // - 'url': Full request URL
 * // - 'app_env': Application environment
 * // - 'app_version': Application version (if defined)
 * //
 * // Custom data from getWatermarkData() merges with defaults
 *
 * @see WatermarkDecorator
 * @see WatermarkDesignPattern
 * @see WatermarkMode
 * @see WatermarkEnabled
 */
trait AsWatermarked
{
    //
}
