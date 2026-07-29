<?php

namespace App\Livewire\Concerns;

use BadMethodCallException;
use Livewire\Component;
use ReflectionMethod;
use ReflectionNamedType;

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

    /** @var list<array{label: string, value: string, mono?: bool, multiline?: bool, link?: bool}>|null */
    public ?array $confirmActionModalDetails = null;

    /**
     * Optional extra opt-in on the confirm dialog (e.g. "also delete the
     * underlying resource"). When a label is set the modal renders a checkbox;
     * its boolean value is appended to the confirmed method's arguments, so the
     * target method receives it as a trailing parameter.
     */
    public ?string $confirmActionModalToggleLabel = null;

    public string $confirmActionModalToggleHint = '';

    public bool $confirmActionModalToggle = false;

    /**
     * @param  list<array{label: string, value: string, mono?: bool, multiline?: bool, link?: bool}>|null  $details
     */
    public function openConfirmActionModal(
        string $method,
        mixed $arguments = [],
        string $title = 'Confirm action',
        string $message = 'Are you sure?',
        string $confirmLabel = 'Confirm',
        bool $destructive = false,
        ?array $details = null,
        ?string $toggleLabel = null,
        string $toggleHint = '',
        bool $toggleDefault = false,
    ): void {
        if (in_array($method, [
            'openConfirmActionModal',
            'closeConfirmActionModal',
            'confirmActionModal',
        ], true)) {
            throw new BadMethodCallException(sprintf('Confirmation target [%s] is not callable.', $method));
        }

        try {
            new ReflectionMethod($this, $method);
        } catch (\ReflectionException) {
            throw new BadMethodCallException(sprintf('Confirmation target [%s] is not callable.', $method));
        }

        $this->confirmActionModalMethod = $method;
        $this->confirmActionModalArguments = is_array($arguments) ? array_values($arguments) : [$arguments];
        $this->confirmActionModalTitle = $title;
        $this->confirmActionModalMessage = $message;
        $this->confirmActionModalConfirmLabel = $confirmLabel;
        $this->confirmActionModalDestructive = $destructive;
        $this->confirmActionModalDetails = $details;
        $this->confirmActionModalToggleLabel = $toggleLabel;
        $this->confirmActionModalToggleHint = $toggleHint;
        $this->confirmActionModalToggle = $toggleDefault;
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
        $this->confirmActionModalDetails = null;
        $this->confirmActionModalToggleLabel = null;
        $this->confirmActionModalToggleHint = '';
        $this->confirmActionModalToggle = false;
    }

    public function confirmActionModal(): mixed
    {
        $method = $this->confirmActionModalMethod;
        $arguments = $this->confirmActionModalArguments;

        // A rendered toggle appends its boolean as the trailing argument, so the
        // confirmed method opts into the extra behaviour (e.g. delete-resource).
        if ($this->confirmActionModalToggleLabel !== null) {
            $arguments[] = $this->confirmActionModalToggle;
        }

        $this->closeConfirmActionModal();

        if ($method === '') {
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
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
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
