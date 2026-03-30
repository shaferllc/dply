<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Action Workflow Builder - Visual workflow builder for actions.
 *
 * Provides programmatic workflow building and visualization.
 *
 * @example
 * // Build a workflow
 * $workflow = ActionWorkflowBuilder::create('Order Processing')
 *     ->add(ValidateOrder::class)
 *     ->add(CheckInventory::class)
 *     ->add(ProcessPayment::class)
 *     ->add(SendConfirmation::class)
 *     ->build();
 * @example
 * // Execute workflow
 * $result = $workflow->execute($order);
 * @example
 * // Export workflow as code
 * $code = ActionWorkflowBuilder::export($workflow);
 * @example
 * // Load workflow from template
 * $workflow = ActionWorkflowBuilder::fromTemplate('ecommerce-order');
 */
class ActionWorkflowBuilder
{
    protected string $name;

    protected array $steps = [];

    protected array $conditions = [];

    protected bool $stopOnFailure = true;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Create a new workflow builder.
     *
     * @param  string  $name  Workflow name
     */
    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * Add a step to the workflow.
     *
     * @param  string|callable  $action  Action class or callable
     * @param  array  $args  Additional arguments
     */
    public function add(string|callable $action, array $args = []): self
    {
        $this->steps[] = [
            'action' => $action,
            'args' => $args,
        ];

        return $this;
    }

    /**
     * Add a conditional step.
     *
     * @param  callable  $condition  Condition callback
     * @param  string|callable  $action  Action to execute if condition is true
     */
    public function when(callable $condition, string|callable $action): self
    {
        $this->steps[] = [
            'type' => 'conditional',
            'condition' => $condition,
            'action' => $action,
        ];

        return $this;
    }

    /**
     * Continue on failure.
     */
    public function continueOnFailure(): self
    {
        $this->stopOnFailure = false;

        return $this;
    }

    /**
     * Build the workflow.
     */
    public function build(): ActionWorkflow
    {
        return new ActionWorkflow(
            $this->name,
            $this->steps,
            $this->stopOnFailure
        );
    }

    /**
     * Export workflow as code.
     *
     * @param  ActionWorkflow  $workflow  Workflow to export
     * @return string Generated code
     */
    public static function export(ActionWorkflow $workflow): string
    {
        $code = "// Workflow: {$workflow->getName()}\n";
        $code .= '// Generated: '.now()->toDateTimeString()."\n\n";
        $code .= "\$workflow = ActionWorkflowBuilder::create('{$workflow->getName()}')\n";

        foreach ($workflow->getSteps() as $step) {
            if (isset($step['type']) && $step['type'] === 'conditional') {
                $code .= "    ->when(fn() => true, {$step['action']}::class)\n";
            } else {
                $action = is_string($step['action']) ? $step['action'].'::class' : '/* callable */';
                $code .= "    ->add({$action})\n";
            }
        }

        if (! $workflow->shouldStopOnFailure()) {
            $code .= "    ->continueOnFailure()\n";
        }

        $code .= "    ->build();\n";

        return $code;
    }

    /**
     * Load workflow from template.
     *
     * @param  string  $templateName  Template name
     */
    public static function fromTemplate(string $templateName): ActionWorkflow
    {
        $templates = static::getTemplates();

        if (! isset($templates[$templateName])) {
            throw new \InvalidArgumentException("Template '{$templateName}' not found");
        }

        $template = $templates[$templateName];
        $builder = static::create($template['name']);

        foreach ($template['steps'] as $step) {
            $builder->add($step['action'], $step['args'] ?? []);
        }

        return $builder->build();
    }

    /**
     * Get available templates.
     *
     * @return array<string, array> Templates
     */
    public static function getTemplates(): array
    {
        return [
            'ecommerce-order' => [
                'name' => 'E-commerce Order Processing',
                'steps' => [
                    ['action' => 'App\Actions\ValidateOrder'],
                    ['action' => 'App\Actions\CheckInventory'],
                    ['action' => 'App\Actions\ProcessPayment'],
                    ['action' => 'App\Actions\SendConfirmation'],
                ],
            ],
        ];
    }
}
