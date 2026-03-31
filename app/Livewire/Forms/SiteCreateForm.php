<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class SiteCreateForm extends Form
{
    public string $name = '';

    public string $type = 'php';

    public string $document_root = '/var/www/app/public';

    public string $repository_path = '/var/www/app';

    public string $php_version = '8.3';

    public ?int $app_port = 3000;

    public string $primary_hostname = '';
}
