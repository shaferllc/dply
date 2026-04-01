<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class ServerCreateForm extends Form
{
    public string $type = 'digitalocean';

    public string $name = '';

    public string $provider_credential_id = '';

    public string $region = '';

    public string $size = '';

    public string $setup_script_key = '';

    public string $ip_address = '';

    public string $ssh_port = '22';

    public string $ssh_user = 'root';

    public string $ssh_private_key = '';

    public string $server_role = 'application';

    public string $cache_service = 'redis';

    public string $webserver = 'nginx';

    public string $php_version = '8.3';

    public string $database = 'mysql84';

    public string $install_profile = 'laravel_app';

    /** @see https://docs.digitalocean.com/reference/api/api-reference/#operation/droplets_create */
    public bool $do_ipv6 = false;

    public bool $do_backups = false;

    public bool $do_monitoring = false;

    public string $do_vpc_uuid = '';

    public string $do_tags = '';

    public string $do_user_data = '';

    public string $do_functions_api_host = '';

    public string $do_functions_namespace = '';

    public string $do_functions_access_key = '';

    public string $do_functions_package = 'default';

    public string $do_functions_action_kind = 'nodejs:18';

    public string $do_functions_action_main = 'index';
}
