<?php

namespace App\Actions;

use App\Actions\Concerns\ValidateActions;
use Illuminate\Foundation\Http\FormRequest;

class ActionRequest extends FormRequest
{
    use ValidateActions;

    public function validateResolved(): void
    {
        // Cancel the auto-resolution trait.
    }

    public function getDefaultValidationData(): array
    {
        return $this->all();
    }
}
