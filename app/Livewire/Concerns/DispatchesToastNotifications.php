<?php

namespace App\Livewire\Concerns;

use App\Support\NotificationToastPosition;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 */
trait DispatchesToastNotifications
{
    protected function toastSuccess(string|\Stringable $message): void
    {
        $this->dispatch(
            'notify',
            message: (string) $message,
            type: 'success',
            position: NotificationToastPosition::resolvedFor(auth()->user()),
        );
    }

    /**
     * Neutral, non-actionable notice. Billing\Show called this for VAT soft
     * warnings before it existed, which fataled the save. toast-stack.blade.php
     * styles only 'error' and 'warning' specially, so 'info' renders with the
     * same neutral treatment as 'success'.
     */
    protected function toastInfo(string|\Stringable $message): void
    {
        $this->dispatch(
            'notify',
            message: (string) $message,
            type: 'info',
            position: NotificationToastPosition::resolvedFor(auth()->user()),
        );
    }

    protected function toastWarning(string|\Stringable $message): void
    {
        $this->dispatch(
            'notify',
            message: (string) $message,
            type: 'warning',
            position: NotificationToastPosition::resolvedFor(auth()->user()),
        );
    }

    protected function toastError(string|\Stringable $message): void
    {
        $this->dispatch(
            'notify',
            message: (string) $message,
            type: 'error',
            position: NotificationToastPosition::resolvedFor(auth()->user()),
        );
    }
}
