<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ActionDependenciesCommand extends Command
{
    protected $signature = 'actions:dependencies
                            {--action= : Show dependencies for specific action}
                            {--format=text : Output format (text, graphviz, json)}';

    protected $description = 'Show action dependencies';

    /**
     * Show action dependencies.
     *
     * @example
     * // Show dependencies for specific action
     * php artisan actions:dependencies --action=App\Actions\ProcessOrder
     * @example
     * // Show all dependencies
     * php artisan actions:dependencies
     * @example
     * // Generate Graphviz output
     * php artisan actions:dependencies --format=graphviz > deps.dot
     * // Then: dot -Tpng deps.dot -o deps.png
     * @example
     * // Generate JSON output
     * php artisan actions:dependencies --format=json > deps.json
     */
    public function handle(): int
    {
        $action = $this->option('action');
        $format = $this->option('format');

        if ($action) {
            $this->showActionDependencies($action, $format);
        } else {
            $this->showAllDependencies($format);
        }

        return Command::SUCCESS;
    }

    protected function showActionDependencies(string $actionClass, string $format): void
    {
        $dependencies = ActionRegistry::getDependencies($actionClass);
        $dependents = ActionRegistry::getDependents($actionClass);

        match ($format) {
            'graphviz' => $this->outputGraphviz($actionClass, $dependencies, $dependents),
            'json' => $this->outputJson($actionClass, $dependencies, $dependents),
            default => $this->outputText($actionClass, $dependencies, $dependents),
        };
    }

    protected function showAllDependencies(string $format): void
    {
        $actions = ActionRegistry::discover();
        $allDeps = [];

        foreach ($actions as $actionClass) {
            $deps = ActionRegistry::getDependencies($actionClass);
            if (! empty($deps)) {
                $allDeps[$actionClass] = $deps;
            }
        }

        match ($format) {
            'graphviz' => $this->outputGraphvizAll($allDeps),
            'json' => $this->outputJsonAll($allDeps),
            default => $this->outputTextAll($allDeps),
        };
    }

    protected function outputText(string $actionClass, array $dependencies, $dependents): void
    {
        $this->line("Dependencies for: {$actionClass}");
        $this->line('');

        if (empty($dependencies)) {
            $this->line('  No dependencies');
        } else {
            foreach ($dependencies as $dep) {
                $this->line("  - {$dep}");
            }
        }

        $this->line('');
        $this->line('Dependents (actions that depend on this):');

        if ($dependents->isEmpty()) {
            $this->line('  None');
        } else {
            foreach ($dependents as $dependent) {
                $this->line("  - {$dependent}");
            }
        }
    }

    protected function outputGraphviz(string $actionClass, array $dependencies, Collection $dependents): void
    {
        $this->line('digraph ActionDependencies {');
        $this->line('  rankdir=LR;');

        foreach ($dependencies as $dep) {
            $this->line("  \"{$dep}\" -> \"{$actionClass}\";");
        }

        foreach ($dependents as $dependent) {
            $this->line("  \"{$actionClass}\" -> \"{$dependent}\";");
        }

        $this->line('}');
    }

    protected function outputJson(string $actionClass, array $dependencies, Collection $dependents): void
    {
        $data = [
            'action' => $actionClass,
            'dependencies' => $dependencies,
            'dependents' => $dependents->toArray(),
        ];

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function outputTextAll(array $allDeps): void
    {
        foreach ($allDeps as $action => $deps) {
            $this->line("{$action}:");
            foreach ($deps as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
        }
    }

    protected function outputGraphvizAll(array $allDeps): void
    {
        $this->line('digraph ActionDependencies {');
        $this->line('  rankdir=LR;');

        foreach ($allDeps as $action => $deps) {
            foreach ($deps as $dep) {
                $this->line("  \"{$dep}\" -> \"{$action}\";");
            }
        }

        $this->line('}');
    }

    protected function outputJsonAll(array $allDeps): void
    {
        $this->line(json_encode($allDeps, JSON_PRETTY_PRINT));
    }
}
