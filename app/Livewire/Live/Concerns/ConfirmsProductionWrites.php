<?php

declare(strict_types=1);

namespace App\Livewire\Live\Concerns;

use App\Services\ProductionData\ProductionDataMirror;
use Livewire\Component;

/**
 * First production write per session requires typing PRODUCTION.
 *
 * @phpstan-require-extends Component
 */
trait ConfirmsProductionWrites
{
    public bool $showProductionWriteConfirm = false;

    public string $productionWriteConfirmInput = '';

    public string $productionWritePendingMethod = '';

    /** @var array<int, mixed> */
    public array $productionWritePendingArguments = [];

    public string $productionWriteConfirmTitle = '';

    public string $productionWriteConfirmMessage = '';

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function runProductionWrite(
        string $method,
        array $arguments = [],
        string $title = '',
        string $message = '',
    ): void {
        if ($this->productionMirror()->writesUnlocked()) {
            $this->{$method}(...$arguments);

            return;
        }

        $this->productionWritePendingMethod = $method;
        $this->productionWritePendingArguments = array_values($arguments);
        $this->productionWriteConfirmTitle = $title !== '' ? $title : __('Confirm production write');
        $this->productionWriteConfirmMessage = $message !== ''
            ? $message
            : __('This changes live production data. Type PRODUCTION to continue for this browser session.');
        $this->productionWriteConfirmInput = '';
        $this->showProductionWriteConfirm = true;
    }

    public function confirmProductionWrite(): void
    {
        if (trim($this->productionWriteConfirmInput) !== 'PRODUCTION') {
            $this->addError('productionWriteConfirmInput', __('Type PRODUCTION exactly to continue.'));

            return;
        }

        $method = $this->productionWritePendingMethod;
        $arguments = $this->productionWritePendingArguments;

        $this->productionMirror()->unlockWrites();
        $this->closeProductionWriteConfirm();

        if ($method !== '' && method_exists($this, $method)) {
            $this->{$method}(...$arguments);
        }
    }

    public function closeProductionWriteConfirm(): void
    {
        $this->showProductionWriteConfirm = false;
        $this->productionWriteConfirmInput = '';
        $this->productionWritePendingMethod = '';
        $this->productionWritePendingArguments = [];
        $this->resetErrorBag('productionWriteConfirmInput');
    }

    abstract protected function productionMirror(): ProductionDataMirror;
}
