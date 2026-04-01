<?php

namespace App\Livewire\Concerns;

use BadMethodCallException;
use ReflectionMethod;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 */
trait ConfirmsActionWithModal
{
    public bool $showConfirmActionModal = false;

    public string $confirmActionModalTitle = '';

    public string $confirmActionModalMessage = '';

    public string $confirmActionModalConfirmLabel = 'Confirm';

    public string $confirmActionModalMethod = '';

    /** @var array<int, mixed> */
    public array $confirmActionModalArguments = [];

    public bool $confirmActionModalDestructive = false;

    /**
     * @param  mixed  $arguments
     */
    public function openConfirmActionModal(
        string $method,
        mixed $arguments = [],
        string $title = 'Confirm action',
        string $message = 'Are you sure?',
        string $confirmLabel = 'Confirm',
        bool $destructive = false,
    ): void {
        if (! method_exists($this, $method) || in_array($method, [
            'openConfirmActionModal',
            'closeConfirmActionModal',
            'confirmActionModal',
        ], true)) {
            throw new BadMethodCallException(sprintf('Confirmation target [%s] is not callable.', $method));
        }

        $this->confirmActionModalMethod = $method;
        $this->confirmActionModalArguments = is_array($arguments) ? array_values($arguments) : [$arguments];
        $this->confirmActionModalTitle = $title;
        $this->confirmActionModalMessage = $message;
        $this->confirmActionModalConfirmLabel = $confirmLabel;
        $this->confirmActionModalDestructive = $destructive;
        $this->showConfirmActionModal = true;
    }

    public function closeConfirmActionModal(): void
    {
        $this->showConfirmActionModal = false;
        $this->confirmActionModalTitle = '';
        $this->confirmActionModalMessage = '';
        $this->confirmActionModalConfirmLabel = 'Confirm';
        $this->confirmActionModalMethod = '';
        $this->confirmActionModalArguments = [];
        $this->confirmActionModalDestructive = false;
    }

    public function confirmActionModal(): mixed
    {
        $method = $this->confirmActionModalMethod;
        $arguments = $this->confirmActionModalArguments;

        $this->closeConfirmActionModal();

        if ($method === '' || ! method_exists($this, $method)) {
            throw new BadMethodCallException('No confirmation target is available.');
        }

        return $this->callConfirmedAction($method, $arguments);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function callConfirmedAction(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($this, $method);
        $resolved = [];
        $argIndex = 0;

        foreach ($reflection->getParameters() as $parameter) {
            if (array_key_exists($argIndex, $arguments)) {
                $resolved[] = $arguments[$argIndex];
                $argIndex++;

                continue;
            }

            $type = $parameter->getType();
            if ($type !== null && ! $type->isBuiltin()) {
                $resolved[] = app()->make($type->getName());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $resolved[] = $parameter->getDefaultValue();

                continue;
            }

            throw new BadMethodCallException(sprintf('Could not resolve parameter [%s] for confirmation target [%s].', $parameter->getName(), $method));
        }

        return $reflection->invokeArgs($this, $resolved);
    }
}
