<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class ServerCreateForm extends Form
{
    /**
     * Wizard mode: 'provider' (Provision with a provider) or 'custom' (Custom/BYO).
     * Set on Step 1 of the create wizard; drives branching on Step 2 and 3.
     */
    public string $mode = 'provider';

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

    public string $custom_host_kind = 'vm';

    /**
     * Provider mode host kind: 'vm' (default — full stack install) or 'docker' (skip stack,
     * just provision the cloud VM with Docker on it). Mirrors custom_host_kind but for the
     * provider/cloud-provisioned path. Set via StepWhere's tile picker or pre-filled by the
     * Containers launcher hint (host_target=docker query param).
     */
    public string $provider_host_kind = 'vm';

    public string $server_role = 'application';

    public string $cache_service = 'redis';

    public string $webserver = 'nginx';

    public string $php_version = '8.3';

    /**
     * Per-language runtime versions, set when the operator picks a stack
     * template (Rails → ruby_version, Next.js → node_version, etc.) and
     * overridable individually on Step 3. Empty string means "not used
     * by this stack" and the form treats it as not-installed.
     */
    public string $ruby_version = '';

    public string $node_version = '';

    public string $python_version = '';

    public string $go_version = '';

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

    public string $aws_lambda_region = 'us-east-1';

    public string $do_kubernetes_cluster_name = '';

    public string $do_kubernetes_namespace = 'default';

    /**
     * AWS region for EKS register-existing — clusters are region-scoped and
     * a single credential can reach any region the user has IAM access to.
     * Defaults to the credential's stored region (set by StepWhat::mount) but
     * the picker lets the user override per-cluster.
     */
    public string $do_kubernetes_aws_region = '';

    /**
     * 'existing' (register a cluster already in the DO/AWS account) or 'new'
     * (have dply call the provider API to provision a brand-new cluster as part
     * of submit). When 'new', the do_kubernetes_new_* fields below describe the
     * cluster spec. EKS only supports 'existing' for now.
     */
    public string $do_kubernetes_source = 'existing';

    public string $do_kubernetes_new_name = '';

    public string $do_kubernetes_new_region = '';

    public string $do_kubernetes_new_node_size = '';

    public int $do_kubernetes_new_node_count = 2;

    public bool $do_kubernetes_new_ha = false;

    /**
     * Kubernetes version slug ("1.30.1-do.0" etc.). Empty means "let DO pick
     * the recommended/latest" — we pass nothing in that case so DO assigns
     * the default; keeps us out of the version-pinning maintenance grind.
     */
    public string $do_kubernetes_new_version = '';

    public string $do_functions_package = 'default';

    public string $do_functions_action_kind = 'nodejs:18';

    public string $do_functions_action_main = 'index';

    /**
     * Org golden-server blueprint applied on Step 3. Empty when a built-in
     * preset or hand-rolled stack is in use.
     */
    public string $server_blueprint_id = '';
}
