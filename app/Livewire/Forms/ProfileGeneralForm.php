<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class ProfileGeneralForm extends Form
{
    public string $name = '';

    public string $email = '';

    public string $country_code = '';

    public string $locale = '';

    public string $timezone = '';
}
