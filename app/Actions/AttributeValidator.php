<?php

namespace App\Actions;

use App\Actions\Concerns\ValidateActions;
use Illuminate\Routing\Redirector;

class AttributeValidator
{
    use ValidateActions;

    public function __construct($action)
    {
        $this->setAction($action);
        $this->redirector = app(Redirector::class);
    }

    public static function for($action): self
    {
        return new static($action);
    }

    public function getDefaultValidationData(): array
    {
        return $this->fromActionMethod('all');
    }
}
