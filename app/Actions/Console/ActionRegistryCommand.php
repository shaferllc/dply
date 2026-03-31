<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionRegistry;
use Illuminate\Console\Command;

class ActionRegistryCommand extends Command
{
    protected $signature = 'actions:registry
                            {--trait= : Filter by trait}
                            {--tag= : Filter by tag}
                            {--search= : Search actions}
                            {--action= : Show details for specific action}';

    protected $description = 'List and search actions in the registry';

    /**
     * List and search actions in the registry.
     *
     * @example
     * // List all actions
     * php artisan actions:registry
     * @example
     * // Filter by trait
     * php artisan actions:registry --trait=App\Actions\Concerns\AsAuthenticated
     * @example
     * // Filter by tag
     * php artisan actions:registry --tag=payment
     * @example
     * // Search actions
     * php artisan actions:registry --search=Order
     * @example
     * // Show details for specific action
     * php artisan actions:registry --action=App\Actions\ProcessOrder
     */
    public function handle(): int
    {
        ActionRegistry::discover();

        if ($action = $this->option('action')) {
            $this->showActionDetails($action);
        } elseif ($trait = $this->option('trait')) {
            $this->showByTrait($trait);
        } elseif ($tag = $this->option('tag')) {
            $this->showByTag($tag);
        } elseif ($search = $this->option('search')) {
            $this->showSearch($search);
        } else {
            $this->showAll();
        }

        return Command::SUCCESS;
    }

    protected function showAll(): void
    {
        $actions = ActionRegistry::all();

        $this->info("Total Actions: {$actions->count()}");
        $this->line('');

        $this->table(
            ['Action', 'Namespace'],
            $actions->map(fn ($action) => [
                class_basename($action),
                (new \ReflectionClass($action))->getNamespaceName(),
            ])->toArray()
        );
    }

    protected function showActionDetails(string $actionClass): void
    {
        $metadata = ActionRegistry::getMetadata($actionClass);
        $dependencies = ActionRegistry::getDependencies($actionClass);
        $dependents = ActionRegistry::getDependents($actionClass);

        $this->info("Action: {$metadata['name']}");
        $this->line("Class: {$metadata['class']}");
        $this->line("Namespace: {$metadata['namespace']}");
        $this->line('');

        if (! empty($metadata['traits'])) {
            $this->line('Traits:');
            foreach ($metadata['traits'] as $trait) {
                $this->line("  - {$trait}");
            }
            $this->line('');
        }

        if (! empty($metadata['handle_params'])) {
            $this->line('Parameters:');
            $this->table(
                ['Name', 'Type', 'Optional'],
                collect($metadata['handle_params'])->map(fn ($p) => [
                    $p['name'],
                    $p['type'] ?? 'mixed',
                    $p['optional'] ? 'Yes' : 'No',
                ])->toArray()
            );
        }

        if (! empty($dependencies)) {
            $this->line('Dependencies:');
            foreach ($dependencies as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
        }

        if (! $dependents->isEmpty()) {
            $this->line('Dependents:');
            foreach ($dependents as $dependent) {
                $this->line("  - {$dependent}");
            }
        }
    }

    protected function showByTrait(string $trait): void
    {
        $actions = ActionRegistry::getByTrait($trait);

        $this->info("Actions using trait: {$trait}");
        $this->line("Count: {$actions->count()}");
        $this->line('');

        $this->table(
            ['Action', 'Class'],
            $actions->map(fn ($action) => [
                class_basename($action),
                $action,
            ])->toArray()
        );
    }

    protected function showByTag(string $tag): void
    {
        $actions = ActionRegistry::getByTag($tag);

        $this->info("Actions with tag: {$tag}");
        $this->line("Count: {$actions->count()}");
        $this->line('');

        if ($actions->isEmpty()) {
            $this->warn('No actions found with this tag.');

            return;
        }

        $this->table(
            ['Action', 'Class'],
            $actions->map(fn ($action) => [
                class_basename($action),
                $action,
            ])->toArray()
        );
    }

    protected function showSearch(string $query): void
    {
        $results = ActionRegistry::search($query);

        $this->info("Search results for: {$query}");
        $this->line("Count: {$results->count()}");
        $this->line('');

        if ($results->isEmpty()) {
            $this->warn('No actions found.');

            return;
        }

        $this->table(
            ['Action', 'Class'],
            $results->map(fn ($action) => [
                class_basename($action),
                $action,
            ])->toArray()
        );
    }
}
