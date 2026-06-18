<?php

declare(strict_types=1);

namespace App\Livewire\Cloud\Concerns;

use App\Models\CloudDeployTask;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ValidatesCloudCreateForm
{


    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size_tier.ends_with' => __('CPU autoscaling needs a Pro-tier size. Pick one of the Pro sizes above, or disable autoscaling.'),
        ];
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'instances' => ['required', 'integer', 'min:1', 'max:50'],
            'size_tier' => ['required', 'in:small,medium,large,xlarge,small-pro,medium-pro,large-pro,xlarge-pro'],
            'region' => ['required', 'string', 'max:50'],
            'backend' => ['required', 'in:auto,digitalocean_app_platform,aws_app_runner'],
            'mode' => ['required', 'in:image,source'],
            'env_file_content' => ['nullable', 'string', 'max:20000'],
        ];

        if ($this->mode === 'source') {
            $rules['repo'] = ['required', 'string', 'max:200'];
            $rules['branch'] = ['required', 'string', 'max:120'];
            $rules['dockerfile_path'] = ['nullable', 'string', 'max:200'];
        } else {
            $rules['image'] = ['required', 'string', 'max:500'];
        }

        if ($this->autoscaling_enabled) {
            $rules['autoscaling_min'] = ['required', 'integer', 'min:1', 'max:50'];
            $rules['autoscaling_max'] = ['required', 'integer', 'min:1', 'max:50', 'gte:autoscaling_min'];
            $rules['autoscaling_cpu_percent'] = ['required', 'integer', 'min:1', 'max:100'];
            // DO App Platform restricts CPU autoscaling to Professional
            // tier instances. Block the bad combo here so we don't ship
            // an unservable spec and bounce off DO's spec validator.
            $rules['size_tier'][] = 'ends_with:-pro';
        }

        if ($this->health_check_enabled) {
            $rules['health_check_path'] = ['required', 'string', 'regex:#^/#'];
            $rules['health_check_period_seconds'] = ['required', 'integer', 'min:1'];
            $rules['health_check_timeout_seconds'] = ['required', 'integer', 'min:1'];
            $rules['health_check_failure_threshold'] = ['required', 'integer', 'min:1'];
        }

        if ($this->databases !== []) {
            // Common per-row constraints. Mode-specific extras (attach
            // needs cloud_database_id; create needs the engine/size knobs)
            // are layered on below per entry — Laravel's array-rule syntax
            // can't express "field X required only when sibling Y equals Z"
            // declaratively, so we fan out the index-keyed rules.
            $rules['databases.*.mode'] = ['required', 'in:attach,create'];
            $rules['databases.*.name'] = ['required', 'string', 'min:3', 'max:60'];
            $rules['databases.*.engine'] = ['required', 'in:postgres,mysql,redis'];
            $rules['databases.*.size'] = ['required', 'in:small,medium,large'];
            $rules['databases.*.version'] = ['nullable', 'string', 'max:20'];
            $rules['databases.*.env_prefix'] = ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]*$/', 'max:40'];

            foreach ($this->databases as $i => $row) {
                $mode = (string) ($row['mode'] ?? '');
                if ($mode === 'attach') {
                    $rules['databases.'.$i.'.cloud_database_id'] = ['required', 'string'];
                }
            }

            // Per-site prefix uniqueness — two attachments can't write
            // the same `${PREFIX}_HOST` etc. The error renders inline on
            // each conflicting row.
            $prefixes = array_map(static fn (array $r): string => strtoupper((string) ($r['env_prefix'] ?? '')), $this->databases);
            $duplicates = array_keys(array_filter(array_count_values($prefixes), static fn (int $n): bool => $n > 1));
            foreach ($this->databases as $i => $row) {
                if (in_array(strtoupper((string) ($row['env_prefix'] ?? '')), $duplicates, true)) {
                    $rules['databases.'.$i.'.env_prefix'][] = function ($attribute, $value, $fail): void {
                        $fail(__('Each database needs a unique env-var prefix on this app.'));
                    };
                }
            }
        }

        if ($this->buckets !== []) {
            $rules['buckets.*.name'] = ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/'];
            $rules['buckets.*.backend'] = ['required', 'in:digitalocean_spaces,aws_s3,cloudflare_r2'];
            $rules['buckets.*.region'] = ['nullable', 'string', 'max:60'];
            $rules['buckets.*.env_prefix'] = ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]*$/', 'max:40'];

            // Bucket-only prefix uniqueness mirrors the database rule.
            // Cross-resource collisions (a bucket and a database both
            // using S3 prefix, for example) are theoretically possible
            // but vanishingly rare given the default prefixes — left
            // for the deploy-time validator to catch if we ever wire it.
            $prefixes = array_map(static fn (array $r): string => strtoupper((string) ($r['env_prefix'] ?? '')), $this->buckets);
            $duplicates = array_keys(array_filter(array_count_values($prefixes), static fn (int $n): bool => $n > 1));
            foreach ($this->buckets as $i => $row) {
                if (in_array(strtoupper((string) ($row['env_prefix'] ?? '')), $duplicates, true)) {
                    $rules['buckets.'.$i.'.env_prefix'][] = function ($attribute, $value, $fail): void {
                        $fail(__('Each bucket needs a unique env-var prefix on this app.'));
                    };
                }
            }
        }

        if ($this->workers !== []) {
            // Workers run inside the same image as the web service. An
            // empty command boots a container that exits immediately and
            // DO marks the whole deploy as "exceeded resource limits or
            // app misbehaving" — surface the gap at submit time instead.
            $rules['workers.*.command'] = ['required', 'string', 'max:500'];
            $rules['workers.*.name'] = ['required', 'string', 'max:60'];
        }

        if ($this->migrations_enabled) {
            $rules['migrations_command'] = ['required', 'string', 'max:500'];
        }

        if ($this->deploy_tasks !== []) {
            $triggers = implode(',', array_keys(CloudDeployTask::DO_KIND_MAP));
            $rules['deploy_tasks.*.trigger'] = ['required', 'string', 'in:'.$triggers];
            $rules['deploy_tasks.*.name'] = ['required', 'string', 'max:60'];
            $rules['deploy_tasks.*.command'] = ['required', 'string', 'max:500'];
            $rules['deploy_tasks.*.size'] = ['nullable', 'string'];
        }

        if ($this->alert_restart_count_enabled) {
            $rules['alert_restart_count_value'] = ['required', 'integer', 'min:1', 'max:100'];
        }
        if ($this->alert_cpu_enabled) {
            $rules['alert_cpu_value'] = ['required', 'integer', 'min:1', 'max:100'];
        }
        if ($this->alert_mem_enabled) {
            $rules['alert_mem_value'] = ['required', 'integer', 'min:1', 'max:100'];
        }
        if ($this->alert_destinations_override_enabled) {
            $rules['alert_destinations_override_slack'] = ['nullable', 'url', 'starts_with:https://', 'max:500'];
            $rules['alert_destinations_override_emails'] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }
}
