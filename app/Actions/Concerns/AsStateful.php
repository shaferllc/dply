<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Maintains state across action calls.
 *
 * Provides utility methods for managing persistent state that survives
 * across multiple action invocations. State is stored in cache and can
 * be scoped per user, per action instance, or custom keys.
 *
 * How it works:
 * - State is stored in cache using a configurable key
 * - State persists across different action instances
 * - State can be scoped per user (default) or custom keys
 * - State has a configurable TTL (default: 1 hour)
 * - State is automatically loaded on first access
 *
 * Benefits:
 * - Maintain state across multiple action calls
 * - Support multi-step processes
 * - Per-user state isolation
 * - Custom state key generation
 * - Configurable TTL
 * - Easy state clearing
 *
 * Note: This is NOT a decorator - it provides utility methods that
 * actions can call explicitly. State management is opt-in and explicit.
 *
 * @example
 * // Basic usage - state persists across calls:
 * class MultiStepProcess extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(string $step, array $data): void
 *     {
 *         $state = $this->getState();
 *         $state[$step] = $data;
 *         $this->setState($state);
 *     }
 * }
 *
 * // Usage:
 * $action1 = MultiStepProcess::make();
 * $action1->handle('step1', ['data' => 'value1']);
 * $action1->handle('step2', ['data' => 'value2']);
 *
 * // State persists - new instance has same state
 * $action2 = MultiStepProcess::make();
 * $state = $action2->getState();
 * // $state = ['step1' => ['data' => 'value1'], 'step2' => ['data' => 'value2']]
 * @example
 * // Custom state key for per-user isolation:
 * class UserPreferences extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(string $key, mixed $value): void
 *     {
 *         $state = $this->getState();
 *         $state[$key] = $value;
 *         $this->setState($state);
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         // State is isolated per user
 *         return 'user_preferences:'.auth()->id();
 *     }
 * }
 *
 * // Usage:
 * $action = UserPreferences::make();
 * $action->handle('theme', 'dark');
 * $action->handle('language', 'en');
 *
 * $preferences = $action->getState();
 * // $preferences = ['theme' => 'dark', 'language' => 'en']
 * @example
 * // Custom state key for shared state:
 * class SharedProcess extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(string $key, mixed $value): void
 *     {
 *         $state = $this->getState();
 *         $state[$key] = $value;
 *         $this->setState($state);
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         // Shared state across all users
 *         return 'shared:process:global';
 *     }
 * }
 *
 * // Usage:
 * $action = SharedProcess::make();
 * $action->handle('status', 'processing');
 *
 * // Any user can access the same state
 * $state = $action->getState();
 * // $state = ['status' => 'processing']
 * @example
 * // Custom TTL for state expiration:
 * class TemporaryData extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(string $key, mixed $value): void
 *     {
 *         $state = $this->getState();
 *         $state[$key] = $value;
 *         $this->setState($state);
 *     }
 *
 *     protected function getStateTtl(): int
 *     {
 *         return 300; // 5 minutes instead of default 1 hour
 *     }
 * }
 *
 * // Usage:
 * $action = TemporaryData::make();
 * $action->handle('temp', 'data');
 * // State expires after 5 minutes
 * @example
 * // Multi-step form processing:
 * class FormWizard extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(string $step, array $formData): array
 *     {
 *         $state = $this->getState();
 *         $state['steps'][$step] = $formData;
 *         $state['current_step'] = $step;
 *         $this->setState($state);
 *
 *         // Return validation or next step info
 *         return [
 *             'step' => $step,
 *             'completed' => array_keys($state['steps'] ?? []),
 *         ];
 *     }
 *
 *     public function complete(): array
 *     {
 *         $state = $this->getState();
 *         $allData = $state['steps'] ?? [];
 *
 *         // Process all collected data
 *         $result = $this->processFormData($allData);
 *
 *         // Clear state after completion
 *         $this->clearState();
 *
 *         return $result;
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         return 'form_wizard:'.auth()->id();
 *     }
 *
 *     protected function processFormData(array $data): array
 *     {
 *         // Process all form steps
 *         return ['success' => true, 'data' => $data];
 *     }
 * }
 *
 * // Usage:
 * $wizard = FormWizard::make();
 *
 * // Step 1
 * $wizard->handle('personal', ['name' => 'John', 'email' => 'john@example.com']);
 *
 * // Step 2
 * $wizard->handle('address', ['street' => '123 Main St', 'city' => 'New York']);
 *
 * // Step 3
 * $wizard->handle('payment', ['card' => '****1234']);
 *
 * // Complete and process all steps
 * $result = $wizard->complete();
 * // State is cleared after completion
 * @example
 * // Progress tracking across async operations:
 * class LongRunningTask extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(int $chunk): void
 *     {
 *         $state = $this->getState();
 *         $processed = $state['processed'] ?? [];
 *         $processed[] = $chunk;
 *         $state['processed'] = $processed;
 *         $state['progress'] = count($processed);
 *         $this->setState($state);
 *     }
 *
 *     public function getProgress(): array
 *     {
 *         $state = $this->getState();
 *
 *         return [
 *             'processed' => $state['processed'] ?? [],
 *             'progress' => $state['progress'] ?? 0,
 *             'total' => 100,
 *         ];
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         return 'task:'.auth()->id().':'.request()->input('task_id');
 *     }
 * }
 *
 * // Usage:
 * $task = LongRunningTask::make();
 *
 * // Process chunks
 * foreach (range(1, 100) as $chunk) {
 *     $task->handle($chunk);
 * }
 *
 * // Check progress from anywhere
 * $progress = $task->getProgress();
 * // $progress = ['processed' => [1, 2, 3, ...], 'progress' => 100, 'total' => 100]
 * @example
 * // Shopping cart state management:
 * class ShoppingCart extends Actions
 * {
 *     use AsStateful;
 *
 *     public function addItem(string $itemId, int $quantity): void
 *     {
 *         $state = $this->getState();
 *         $items = $state['items'] ?? [];
 *
 *         if (isset($items[$itemId])) {
 *             $items[$itemId] += $quantity;
 *         } else {
 *             $items[$itemId] = $quantity;
 *         }
 *
 *         $state['items'] = $items;
 *         $state['updated_at'] = now()->toIso8601String();
 *         $this->setState($state);
 *     }
 *
 *     public function removeItem(string $itemId): void
 *     {
 *         $state = $this->getState();
 *         $items = $state['items'] ?? [];
 *         unset($items[$itemId]);
 *         $state['items'] = $items;
 *         $this->setState($state);
 *     }
 *
 *     public function getCart(): array
 *     {
 *         return $this->getState();
 *     }
 *
 *     public function clearCart(): void
 *     {
 *         $this->clearState();
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         return 'cart:'.auth()->id();
 *     }
 *
 *     protected function getStateTtl(): int
 *     {
 *         return 86400; // 24 hours
 *     }
 * }
 *
 * // Usage:
 * $cart = ShoppingCart::make();
 * $cart->addItem('product-123', 2);
 * $cart->addItem('product-456', 1);
 *
 * $cartData = $cart->getCart();
 * // $cartData = ['items' => ['product-123' => 2, 'product-456' => 1], 'updated_at' => '...']
 *
 * // Cart persists across requests
 * $cart2 = ShoppingCart::make();
 * $cart2->addItem('product-789', 1);
 *
 * // Clear when done
 * $cart->clearCart();
 * @example
 * // Livewire Integration - Multi-step form wizard:
 * // Action with stateful:
 * class ProcessRegistration extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(string $step, array $data): array
 *     {
 *         $state = $this->getState();
 *         $state['steps'][$step] = $data;
 *         $state['current_step'] = $step;
 *         $this->setState($state);
 *
 *         return [
 *             'step' => $step,
 *             'completed' => array_keys($state['steps'] ?? []),
 *             'data' => $state['steps'],
 *         ];
 *     }
 *
 *     public function complete(): array
 *     {
 *         $state = $this->getState();
 *         $allData = $state['steps'] ?? [];
 *
 *         // Process registration
 *         $user = $this->createUser($allData);
 *
 *         $this->clearState();
 *
 *         return ['success' => true, 'user' => $user];
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         return 'registration:'.auth()->id();
 *     }
 *
 *     protected function createUser(array $data): User
 *     {
 *         // Create user from all collected data
 *         return User::create($data);
 *     }
 * }
 *
 * // Livewire Component:
 * class RegistrationWizard extends Component
 * {
 *     public string $currentStep = 'personal';
 *     public array $steps = ['personal', 'contact', 'preferences'];
 *
 *     public function saveStep(string $step, array $data): void
 *     {
 *         // Save step data to persistent state
 *         $result = ProcessRegistration::run($step, $data);
 *
 *         // Update UI
 *         $this->currentStep = $this->getNextStep($step);
 *         $this->dispatch('step-saved', step: $step);
 *     }
 *
 *     public function complete(): void
 *     {
 *         $result = ProcessRegistration::run('complete', []);
 *
 *         if ($result['success']) {
 *             $this->redirect(route('dashboard'));
 *         }
 *     }
 *
 *     public function getProgress(): array
 *     {
 *         // Get state from action to show progress
 *         $action = ProcessRegistration::make();
 *         $state = $action->getState();
 *
 *         return [
 *             'current' => $state['current_step'] ?? 'personal',
 *             'completed' => array_keys($state['steps'] ?? []),
 *         ];
 *     }
 *
 *     protected function getNextStep(string $current): string
 *     {
 *         $index = array_search($current, $this->steps);
 *
 *         return $this->steps[$index + 1] ?? 'complete';
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.registration-wizard');
 *     }
 * }
 *
 * // State persists even if user refreshes page or navigates away!
 * @example
 * // Livewire Integration - Shopping cart with persistence:
 * // Action with stateful:
 * class ManageCart extends Actions
 * {
 *     use AsStateful;
 *
 *     public function addItem(string $productId, int $quantity): void
 *     {
 *         $state = $this->getState();
 *         $items = $state['items'] ?? [];
 *
 *         if (isset($items[$productId])) {
 *             $items[$productId] += $quantity;
 *         } else {
 *             $items[$productId] = $quantity;
 *         }
 *
 *         $state['items'] = $items;
 *         $state['updated_at'] = now()->toIso8601String();
 *         $this->setState($state);
 *     }
 *
 *     public function removeItem(string $productId): void
 *     {
 *         $state = $this->getState();
 *         $items = $state['items'] ?? [];
 *         unset($items[$productId]);
 *         $state['items'] = $items;
 *         $this->setState($state);
 *     }
 *
 *     public function getCart(): array
 *     {
 *         return $this->getState();
 *     }
 *
 *     public function clearCart(): void
 *     {
 *         $this->clearState();
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         return 'cart:'.auth()->id();
 *     }
 *
 *     protected function getStateTtl(): int
 *     {
 *         return 86400; // 24 hours
 *     }
 * }
 *
 * // Livewire Component:
 * class ShoppingCart extends Component
 * {
 *     public function addToCart(string $productId, int $quantity = 1): void
 *     {
 *         ManageCart::run('addItem', [$productId, $quantity]);
 *         $this->dispatch('cart-updated');
 *     }
 *
 *     public function removeFromCart(string $productId): void
 *     {
 *         ManageCart::run('removeItem', [$productId]);
 *         $this->dispatch('cart-updated');
 *     }
 *
 *     public function getCartItemsProperty(): array
 *     {
 *         $cart = ManageCart::make();
 *         $state = $cart->getCart();
 *
 *         return $state['items'] ?? [];
 *     }
 *
 *     public function checkout(): void
 *     {
 *         $cart = ManageCart::make();
 *         $items = $cart->getCart()['items'] ?? [];
 *
 *         if (empty($items)) {
 *             $this->dispatch('cart-empty');
 *
 *             return;
 *         }
 *
 *         // Process checkout
 *         $order = $this->processOrder($items);
 *         $cart->clearCart();
 *
 *         $this->redirect(route('orders.show', $order));
 *     }
 *
 *     protected function processOrder(array $items): Order
 *     {
 *         // Create order from cart items
 *         return Order::create(['items' => $items]);
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.shopping-cart', [
 *             'items' => $this->cartItems,
 *         ]);
 *     }
 * }
 *
 * // Cart persists across page refreshes and navigation!
 * @example
 * // Livewire Integration - Progress tracking across async operations:
 * // Action with stateful:
 * class TrackImportProgress extends Actions
 * {
 *     use AsStateful;
 *
 *     public function handle(int $processed, int $total): void
 *     {
 *         $state = $this->getState();
 *         $state['processed'] = $processed;
 *         $state['total'] = $total;
 *         $state['progress'] = ($processed / $total) * 100;
 *         $state['updated_at'] = now()->toIso8601String();
 *         $this->setState($state);
 *     }
 *
 *     public function getProgress(): array
 *     {
 *         return $this->getState();
 *     }
 *
 *     public function reset(): void
 *     {
 *         $this->clearState();
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         return 'import:'.auth()->id().':'.request()->input('import_id');
 *     }
 * }
 *
 * // Livewire Component:
 * class ImportProgress extends Component
 * {
 *     public string $importId;
 *
 *     public function mount(string $importId): void
 *     {
 *         $this->importId = $importId;
 *     }
 *
 *     public function getProgressProperty(): array
 *     {
 *         // Get progress from persistent state
 *         $action = TrackImportProgress::make();
 *
 *         return $action->getProgress();
 *     }
 *
 *     public function pollProgress(): void
 * {
 *         // Livewire polling will call this method
 *         // Progress is read from cache, so it works even if
 *         // the import job is running in background
 *         $this->dispatch('progress-updated');
 *     }
 *
 *     public function render(): View
 *     {
 *         $progress = $this->progress;
 *
 *         return view('livewire.import-progress', [
 *             'processed' => $progress['processed'] ?? 0,
 *             'total' => $progress['total'] ?? 0,
 *             'percentage' => $progress['progress'] ?? 0,
 *         ]);
 *     }
 * }
 *
 * // In Blade template, use wire:poll to auto-refresh:
 * // <div wire:poll.2s="pollProgress">
 * //     Progress: {{ $percentage }}%
 * // </div>
 *
 * // Background job updates state, Livewire reads it!
 * @example
 * // Livewire Integration - Syncing component state with action state:
 * // Action with stateful:
 * class ManageFormData extends Actions
 * {
 *     use AsStateful;
 *
 *     public function saveStep(string $step, array $data): void
 *     {
 *         $state = $this->getState();
 *         $state[$step] = $data;
 *         $this->setState($state);
 *     }
 *
 *     public function getData(): array
 *     {
 *         return $this->getState();
 *     }
 *
 *     public function clear(): void
 *     {
 *         $this->clearState();
 *     }
 *
 *     protected function getStateKey(): string
 *     {
 *         return 'form:'.auth()->id();
 *     }
 * }
 *
 * // Livewire Component with state sync:
 * class MultiStepForm extends Component
 * {
 *     public string $step = 'step1';
 *     public array $formData = [];
 *
 *     public function mount(): void
 *     {
 *         // Load existing state from action on mount
 *         $this->loadState();
 *     }
 *
 *     public function saveStep(): void
 *     {
 *         // Save to both Livewire property and action state
 *         ManageFormData::run('saveStep', [$this->step, $this->formData]);
 *
 *         $this->nextStep();
 *     }
 *
 *     public function nextStep(): void
 *     {
 *         $steps = ['step1', 'step2', 'step3'];
 *         $currentIndex = array_search($this->step, $steps);
 *         $this->step = $steps[$currentIndex + 1] ?? 'complete';
 *
 *         // Load state for next step
 *         $this->loadState();
 *     }
 *
 *     protected function loadState(): void
 *     {
 *         // Sync Livewire component state with action state
 *         $action = ManageFormData::make();
 *         $state = $action->getData();
 *
 *         // Load current step data into component
 *         $this->formData = $state[$this->step] ?? [];
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.multi-step-form');
 *     }
 * }
 *
 * // State syncs between Livewire component and action!
 * // User can refresh page and continue where they left off
 */
trait AsStateful
{
    protected ?array $state = null;

    protected function getState(): array
    {
        if ($this->state === null) {
            $key = $this->getStateKey();
            $this->state = Cache::get($key, []);
        }

        return $this->state;
    }

    protected function setState(array $state): void
    {
        $this->state = $state;
        $key = $this->getStateKey();
        Cache::put($key, $state, $this->getStateTtl());
    }

    protected function clearState(): void
    {
        $this->state = [];
        Cache::forget($this->getStateKey());
    }

    protected function getStateKey(): string
    {
        return $this->hasMethod('getStateKey')
            ? $this->callMethod('getStateKey')
            : 'state:'.get_class($this).':'.(auth()->id() ?? 'guest');
    }

    protected function getStateTtl(): int
    {
        return $this->hasMethod('getStateTtl')
            ? $this->callMethod('getStateTtl')
            : 3600; // 1 hour
    }
}
