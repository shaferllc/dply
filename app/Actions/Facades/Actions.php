<?php

namespace App\Actions\Facades;

use App\Actions\ActionManager;
use Illuminate\Support\Facades\Facade;

/**
 * @see ActionManager
 *
 * @method static void registerRoutesForAction(string $className)
 * @method static void registerCommandsForAction(string $className)
 */
class Actions extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ActionManager::class;
    }
}
