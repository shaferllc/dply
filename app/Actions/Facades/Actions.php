<?php

namespace App\Actions\Facades;

use App\Actions\ActionManager;
use Illuminate\Support\Facades\Facade;

/**
 * @see ActionManager
 *
 * @method static void registerRoutes($paths = 'app/Actions')
 * @method static void registerCommands($paths = 'app/Actions')
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
