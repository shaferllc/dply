<?php

namespace App\Actions;

use App\Actions\Concerns\ValidateActions;
use Illuminate\Routing\Redirector;

class AttributeValidator
{
    use ValidateActions;

    /**
     * @param  mixed  $action
     */
    public function __construct($action)
    {
        $this->setAction($action);
        $this->redirector = app(Redirector::class);
    }

    /**
     * @param  mixed  $action
     */
    public static function for($action): self
    {
        return new static($action);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultValidationData(): array
    {
        return $this->fromActionMethod('all');
    }
}
