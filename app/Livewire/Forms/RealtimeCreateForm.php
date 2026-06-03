<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class RealtimeCreateForm extends Form
{
    #[Validate('required|string|min:2|max:255')]
    public string $name = '';

    /**
     * @return array{name: string}
     */
    public function toArray(): array
    {
        return ['name' => trim($this->name)];
    }
}
