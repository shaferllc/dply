<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

/**
 * Broadcasts events to channels (Pusher, Redis, etc.) after action execution.
 *
 * This trait is a marker that enables automatic broadcasting via BroadcastDecorator.
 * When an action uses AsBroadcast, BroadcastDesignPattern recognizes it and
 * ActionManager wraps the action with BroadcastDecorator.
 *
 * How it works:
 * 1. Action uses AsBroadcast trait (marker)
 * 2. BroadcastDesignPattern recognizes the trait
 * 3. ActionManager wraps action with BroadcastDecorator
 * 4. When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Captures the result
 *    - Broadcasts the result to configured channels
 *    - Uses custom channel, event name, and payload if provided
 *
 * Features:
 * - Automatic broadcasting after execution
 * - Configurable broadcast channels
 * - Custom event names
 * - Custom payload formatting
 * - Works with Laravel's broadcasting system (Pusher, Redis, etc.)
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Real-time updates to frontend
 * - Decoupled event broadcasting
 * - Configurable per action
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - Real-time notifications
 * - Live updates to dashboards
 * - Chat applications
 * - Collaborative editing
 * - Live status updates
 * - Activity feeds
 * - Progress tracking
 * - Game state synchronization
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * BroadcastDecorator, which automatically wraps actions and adds broadcasting.
 * This follows the same pattern as AsDebounced, AsLock, AsLogger, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getBroadcastChannel(...$arguments)` method to customize channel
 * - Set `getBroadcastEventName()` method to customize event name
 * - Set `getBroadcastPayload($result, $arguments)` method to customize payload
 *
 * @example
 * // ============================================
 * // Example 1: Basic Status Update Broadcasting
 * // ============================================
 * class UpdateUserStatus extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(User $user, string $status): void
 *     {
 *         $user->update(['status' => $status]);
 *     }
 *
 *     protected function getBroadcastChannel(User $user): string
 *     {
 *         return "user.{$user->id}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'status.updated';
 *     }
 *
 *     protected function getBroadcastPayload($result, array $arguments): array
 *     {
 *         [$user] = $arguments;
 *         return [
 *             'user_id' => $user->id,
 *             'status' => $user->status,
 *             'updated_at' => $user->updated_at->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * UpdateUserStatus::run($user, 'online');
 * // Automatically broadcasts to "user.{id}" channel with event "status.updated"
 * @example
 * // ============================================
 * // Example 2: Real-Time Chat Message Broadcasting
 * // ============================================
 * class SendChatMessage extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(User $user, Room $room, string $message): Message
 *     {
 *         return Message::create([
 *             'user_id' => $user->id,
 *             'room_id' => $room->id,
 *             'content' => $message,
 *         ]);
 *     }
 *
 *     protected function getBroadcastChannel(User $user, Room $room): string
 *     {
 *         return "room.{$room->id}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'message.sent';
 *     }
 *
 *     protected function getBroadcastPayload(Message $message, array $arguments): array
 *     {
 *         return [
 *             'id' => $message->id,
 *             'user' => [
 *                 'id' => $message->user->id,
 *                 'name' => $message->user->name,
 *                 'avatar' => $message->user->avatar_url,
 *             ],
 *             'content' => $message->content,
 *             'created_at' => $message->created_at->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * $message = SendChatMessage::run($user, $room, 'Hello everyone!');
 * // Broadcasts to "room.{id}" channel with full message data
 * @example
 * // ============================================
 * // Example 3: Live Dashboard Updates
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->update(['status' => 'processing']);
 *         // ... process order logic ...
 *         $order->update(['status' => 'completed']);
 *
 *         return $order->fresh();
 *     }
 *
 *     protected function getBroadcastChannel(Order $order): string
 *     {
 *         return "orders.{$order->id}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'order.updated';
 *     }
 *
 *     protected function getBroadcastPayload(Order $order, array $arguments): array
 *     {
 *         return [
 *             'order_id' => $order->id,
 *             'status' => $order->status,
 *             'total' => $order->total,
 *             'items_count' => $order->items->count(),
 *             'updated_at' => $order->updated_at->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * $order = ProcessOrder::run($order);
 * // Frontend receives real-time updates on order status
 * @example
 * // ============================================
 * // Example 4: Collaborative Editing
 * // ============================================
 * class UpdateDocument extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(Document $document, User $user, string $content): Document
 *     {
 *         $document->update([
 *             'content' => $content,
 *             'last_edited_by' => $user->id,
 *             'last_edited_at' => now(),
 *         ]);
 *
 *         return $document->fresh();
 *     }
 *
 *     protected function getBroadcastChannel(Document $document): string
 *     {
 *         return "document.{$document->id}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'document.updated';
 *     }
 *
 *     protected function getBroadcastPayload(Document $document, array $arguments): array
 *     {
 *         [, $user] = $arguments;
 *         return [
 *             'document_id' => $document->id,
 *             'content' => $document->content,
 *             'editor' => [
 *                 'id' => $user->id,
 *                 'name' => $user->name,
 *             ],
 *             'edited_at' => $document->last_edited_at->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * UpdateDocument::run($document, $user, 'New content here');
 * // All users editing the document receive real-time updates
 * @example
 * // ============================================
 * // Example 5: Activity Feed Updates
 * // ============================================
 * class CreatePost extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(User $user, string $content): Post
 *     {
 *         return Post::create([
 *             'user_id' => $user->id,
 *             'content' => $content,
 *         ]);
 *     }
 *
 *     protected function getBroadcastChannel(User $user): string
 *     {
 *         // Broadcast to user's followers
 *         return "user.{$user->id}.followers";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'post.created';
 *     }
 *
 *     protected function getBroadcastPayload(Post $post, array $arguments): array
 *     {
 *         return [
 *             'post' => [
 *                 'id' => $post->id,
 *                 'content' => $post->content,
 *                 'author' => [
 *                     'id' => $post->user->id,
 *                     'name' => $post->user->name,
 *                     'avatar' => $post->user->avatar_url,
 *                 ],
 *                 'created_at' => $post->created_at->toIso8601String(),
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage
 * $post = CreatePost::run($user, 'Just published a new article!');
 * // All followers receive the new post in their activity feed
 * @example
 * // ============================================
 * // Example 6: Game State Synchronization
 * // ============================================
 * class MovePlayer extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(Game $game, Player $player, array $position): Game
 *     {
 *         $player->update(['position' => $position]);
 *         $game->touch(); // Update game timestamp
 *
 *         return $game->fresh();
 *     }
 *
 *     protected function getBroadcastChannel(Game $game): string
 *     {
 *         return "game.{$game->id}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'player.moved';
 *     }
 *
 *     protected function getBroadcastPayload(Game $game, array $arguments): array
 *     {
 *         [, $player, $position] = $arguments;
 *         return [
 *             'game_id' => $game->id,
 *             'player' => [
 *                 'id' => $player->id,
 *                 'name' => $player->name,
 *                 'position' => $position,
 *             ],
 *             'timestamp' => now()->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * MovePlayer::run($game, $player, ['x' => 100, 'y' => 200]);
 * // All players in the game receive the position update
 * @example
 * // ============================================
 * // Example 7: Progress Tracking
 * // ============================================
 * class ProcessBatch extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(Batch $batch): Batch
 *     {
 *         // Process items
 *         $processed = 0;
 *         foreach ($batch->items as $item) {
 *             // ... process item ...
 *             $processed++;
 *         }
 *
 *         $batch->update(['processed_count' => $processed]);
 *
 *         return $batch->fresh();
 *     }
 *
 *     protected function getBroadcastChannel(Batch $batch): string
 *     {
 *         return "batch.{$batch->id}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'batch.progress';
 *     }
 *
 *     protected function getBroadcastPayload(Batch $batch, array $arguments): array
 *     {
 *         return [
 *             'batch_id' => $batch->id,
 *             'processed' => $batch->processed_count,
 *             'total' => $batch->items->count(),
 *             'percentage' => ($batch->processed_count / $batch->items->count()) * 100,
 *             'status' => $batch->status,
 *         ];
 *     }
 * }
 *
 * // Usage
 * $batch = ProcessBatch::run($batch);
 * // Frontend shows real-time progress bar updates
 * @example
 * // ============================================
 * // Example 8: Notification Broadcasting
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(User $user, string $message, string $type): Notification
 *     {
 *         return Notification::create([
 *             'user_id' => $user->id,
 *             'message' => $message,
 *             'type' => $type,
 *             'read' => false,
 *         ]);
 *     }
 *
 *     protected function getBroadcastChannel(User $user): string
 *     {
 *         return "user.{$user->id}.notifications";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'notification.received';
 *     }
 *
 *     protected function getBroadcastPayload(Notification $notification, array $arguments): array
 *     {
 *         return [
 *             'id' => $notification->id,
 *             'message' => $notification->message,
 *             'type' => $notification->type,
 *             'read' => $notification->read,
 *             'created_at' => $notification->created_at->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * SendNotification::run($user, 'You have a new message', 'info');
 * // User receives real-time notification in their browser
 * @example
 * // ============================================
 * // Example 9: Live Auction Bidding
 * // ============================================
 * class PlaceBid extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(Auction $auction, User $user, float $amount): Bid
 *     {
 *         if ($amount <= $auction->current_bid) {
 *             throw new \InvalidArgumentException('Bid must be higher than current bid');
 *         }
 *
 *         $auction->update(['current_bid' => $amount, 'current_bidder_id' => $user->id]);
 *
 *         return Bid::create([
 *             'auction_id' => $auction->id,
 *             'user_id' => $user->id,
 *             'amount' => $amount,
 *         ]);
 *     }
 *
 *     protected function getBroadcastChannel(Auction $auction): string
 *     {
 *         return "auction.{$auction->id}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'bid.placed';
 *     }
 *
 *     protected function getBroadcastPayload(Bid $bid, array $arguments): array
 *     {
 *         [$auction, $user] = $arguments;
 *         return [
 *             'bid_id' => $bid->id,
 *             'auction_id' => $auction->id,
 *             'bidder' => [
 *                 'id' => $user->id,
 *                 'name' => $user->name,
 *             ],
 *             'amount' => $bid->amount,
 *             'placed_at' => $bid->created_at->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * PlaceBid::run($auction, $user, 1500.00);
 * // All auction watchers see the new bid in real-time
 * @example
 * // ============================================
 * // Example 10: Stock Price Updates
 * // ============================================
 * class UpdateStockPrice extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(Stock $stock, float $price): Stock
 *     {
 *         $stock->update([
 *             'current_price' => $price,
 *             'last_updated' => now(),
 *         ]);
 *
 *         return $stock->fresh();
 *     }
 *
 *     protected function getBroadcastChannel(Stock $stock): string
 *     {
 *         return "stock.{$stock->symbol}";
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'price.updated';
 *     }
 *
 *     protected function getBroadcastPayload(Stock $stock, array $arguments): array
 *     {
 *         return [
 *             'symbol' => $stock->symbol,
 *             'price' => $stock->current_price,
 *             'change' => $stock->price_change,
 *             'change_percent' => $stock->price_change_percent,
 *             'updated_at' => $stock->last_updated->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage
 * UpdateStockPrice::run($stock, 125.50);
 * // All users watching this stock receive real-time price updates
 * @example
 * // ============================================
 * // Example 11: Default Channel (No Custom Channel)
 * // ============================================
 * class SimpleAction extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(string $data): array
 *     {
 *         return ['processed' => $data];
 *     }
 *
 *     // No getBroadcastChannel() method - broadcasting is skipped
 *     // No getBroadcastEventName() method - uses class basename "SimpleAction"
 *     // No getBroadcastPayload() method - uses default payload
 * }
 *
 * // Usage
 * SimpleAction::run('test');
 * // No broadcast (channel is null)
 * @example
 * // ============================================
 * // Example 12: Custom Event Name Only
 * // ============================================
 * class CustomEventAction extends Actions
 * {
 *     use AsBroadcast;
 *
 *     public function handle(): void
 *     {
 *         // Do something
 *     }
 *
 *     protected function getBroadcastChannel(): string
 *     {
 *         return 'global.updates';
 *     }
 *
 *     protected function getBroadcastEventName(): string
 *     {
 *         return 'custom.event.name';
 *     }
 *
 *     // Uses default payload with action class, result, and timestamp
 * }
 *
 * // Usage
 * CustomEventAction::run();
 * // Broadcasts to "global.updates" with event "custom.event.name"
 */
trait AsBroadcast
{
    // This is a marker trait - the actual broadcasting functionality is handled by BroadcastDecorator
    // via the BroadcastDesignPattern and ActionManager
}
