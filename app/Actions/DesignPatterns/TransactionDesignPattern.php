<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsTransaction;
use App\Actions\Decorators\TransactionDecorator;

/**
 * Transaction Design Pattern
 *
 * Recognizes actions that use the AsTransaction trait and wraps them
 * with TransactionDecorator to automatically handle database transactions.
 */
class TransactionDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsTransaction::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return new TransactionDecorator($instance);
    }
}
