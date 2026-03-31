<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class ProfileBillingForm extends Form
{
    public string $invoice_email = '';

    public string $vat_number = '';

    public string $billing_currency = '';

    public string $billing_details = '';
}
