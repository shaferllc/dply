<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use App\Actions\Exceptions\MissingCommandSignatureException;
use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;

class CommandDecorator extends Command
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);

        $this->signature = $this->fromActionMethodOrProperty('getCommandSignature', 'commandSignature');
        $this->name = $this->fromActionMethodOrProperty('getCommandName', 'commandName');
        $this->description = $this->fromActionMethodOrProperty('getCommandDescription', 'commandDescription');
        $this->help = $this->fromActionMethodOrProperty('getCommandHelp', 'commandHelp');
        $this->hidden = $this->fromActionMethodOrProperty('isCommandHidden', 'commandHidden', false);

        if (! $this->signature) {
            throw new MissingCommandSignatureException($this->action);
        }

        parent::__construct();
    }

    public function handle()
    {
        if ($this->hasMethod('asCommand')) {
            return $this->resolveAndCallMethod('asCommand', ['command' => $this]);
        }

        if ($this->hasMethod('handle')) {
            return $this->resolveAndCallMethod('handle', ['command' => $this]);
        }
    }

    public function getComponents(): Factory
    {
        return $this->components;
    }
}
