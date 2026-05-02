<?php

namespace App\Livewire\Concerns;

use App\Support\NotificationToastPosition;

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
