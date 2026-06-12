<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\SitePhpRuntimeDirectivesBuilder;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSitePhpFpm
{
    public string $php_fpm_user = '';

    public string $php_version = '';

    public string $php_memory_limit = '';

    public string $php_upload_max_filesize = '';

    public string $php_max_execution_time = '';

    public string $php_post_max_size = '';

    public string $php_max_input_time = '';

    public string $php_max_input_vars = '';

    public string $php_max_file_uploads = '';

    public string $php_timezone = '';

    public string $fpm_pm = 'dynamic';

    public string $fpm_max_children = '';

    public string $fpm_max_requests = '';

    public string $fpm_request_terminate_timeout = '';

    public function savePhpSettings(ServerPhpManager $phpManager): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->toastError(__('This host runtime does not expose machine PHP settings.'));

            return;
        }

        $phpData = $phpManager->sitePhpData($this->server->fresh(), $this->site->fresh());
        $installedVersions = collect($phpData['installed_versions'] ?? [])
            ->filter(fn (mixed $version): bool => is_array($version) && (bool) ($version['is_supported'] ?? false))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        $rules = [
            'php_version' => ['required', 'string'],
            'php_memory_limit' => ['nullable', 'string', 'max:32', 'regex:/^\d+[KMG]?$/i'],
            'php_upload_max_filesize' => ['nullable', 'string', 'max:32', 'regex:/^\d+[KMG]?$/i'],
            'php_post_max_size' => ['nullable', 'string', 'max:32', 'regex:/^\d+[KMG]?$/i', function (string $attribute, mixed $value, callable $fail): void {
                // PHP requires post_max_size >= upload_max_filesize, else uploads
                // silently fail. `0` means unlimited, so it always satisfies.
                if (! is_string($value) || $value === '' || $this->php_upload_max_filesize === '') {
                    return;
                }
                $post = SitePhpRuntimeDirectivesBuilder::shorthandBytes($value);
                if ($post === 0) {
                    return;
                }
                if ($post < SitePhpRuntimeDirectivesBuilder::shorthandBytes($this->php_upload_max_filesize)) {
                    $fail(__('Post max size must be at least as large as the upload max filesize.'));
                }
            }],
            'php_max_execution_time' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'php_max_input_time' => ['nullable', 'integer', 'min:-1', 'max:3600'],
            'php_max_input_vars' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'php_max_file_uploads' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'php_timezone' => ['nullable', 'string', 'timezone'],
        ];

        if ($installedVersions !== []) {
            $rules['php_version'][] = 'in:'.implode(',', $installedVersions);
        }

        $validated = $this->validate($rules, [
            'php_version.in' => __('Choose a PHP version that is currently installed on this server.'),
            'php_memory_limit.regex' => __('Use a PHP size like 256M or 1G.'),
            'php_upload_max_filesize.regex' => __('Use a PHP size like 64M or 1G.'),
            'php_post_max_size.regex' => __('Use a PHP size like 64M or 1G.'),
            'php_timezone.timezone' => __('Choose a valid PHP timezone like UTC or America/New_York.'),
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['php_runtime'] = [
            'memory_limit' => $validated['php_memory_limit'] !== '' ? $validated['php_memory_limit'] : null,
            'upload_max_filesize' => $validated['php_upload_max_filesize'] !== '' ? $validated['php_upload_max_filesize'] : null,
            'post_max_size' => $validated['php_post_max_size'] !== '' ? $validated['php_post_max_size'] : null,
            'max_execution_time' => $validated['php_max_execution_time'] !== '' ? (string) $validated['php_max_execution_time'] : null,
            'max_input_time' => $validated['php_max_input_time'] !== '' ? (string) $validated['php_max_input_time'] : null,
            'max_input_vars' => $validated['php_max_input_vars'] !== '' ? (string) $validated['php_max_input_vars'] : null,
            'max_file_uploads' => $validated['php_max_file_uploads'] !== '' ? (string) $validated['php_max_file_uploads'] : null,
            'timezone' => $validated['php_timezone'] !== '' ? $validated['php_timezone'] : null,
        ];

        // PHP-version writes now flow through runtime_version (the
        // canonical column post-php_version-drop). Always pin runtime
        // to 'php' on the way out so future reads of runtimeKey() agree.
        $oldVersion = $this->site->runtime_version;
        $this->site->runtime = 'php';
        $this->site->runtime_version = $validated['php_version'];
        $this->site->meta = $meta;
        $this->site->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.php_settings_updated', $this->site, [
                'runtime_version' => $oldVersion,
            ], [
                'runtime_version' => $validated['php_version'],
                'memory_limit' => $meta['php_runtime']['memory_limit'] ?? null,
                'upload_max_filesize' => $meta['php_runtime']['upload_max_filesize'] ?? null,
                'post_max_size' => $meta['php_runtime']['post_max_size'] ?? null,
                'max_execution_time' => $meta['php_runtime']['max_execution_time'] ?? null,
                'max_input_time' => $meta['php_runtime']['max_input_time'] ?? null,
                'max_input_vars' => $meta['php_runtime']['max_input_vars'] ?? null,
                'max_file_uploads' => $meta['php_runtime']['max_file_uploads'] ?? null,
                'timezone' => $meta['php_runtime']['timezone'] ?? null,
            ]);
        }

        // Push the new limits (and any version switch) to the box. The
        // FastCGI PHP_VALUE directives are rebuilt and the webserver reloaded;
        // without this the form would only persist meta and change nothing.
        if ($this->shouldAutoReapplyManagedWebserverConfig()) {
            ApplySiteWebserverConfigJob::dispatch($this->site->id);
            $this->toastSuccess(__('PHP settings saved. Webserver config queued.'));
        } else {
            $this->toastSuccess(__('PHP settings saved.'));
        }

        $this->syncFormFromSite();
    }

    public function savePhpFpmPool(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->toastError(__('This host runtime does not expose PHP-FPM pool settings.'));

            return;
        }

        $validated = $this->validate([
            'fpm_pm' => ['required', 'string', 'in:dynamic,static,ondemand'],
            'fpm_max_children' => ['required', 'integer', 'min:1', 'max:1000'],
            'fpm_max_requests' => ['required', 'integer', 'min:0', 'max:100000'],
            'fpm_request_terminate_timeout' => ['required', 'integer', 'min:1', 'max:3600'],
        ], [], [
            'fpm_pm' => 'process manager',
            'fpm_max_children' => 'max children',
            'fpm_max_requests' => 'max requests',
            'fpm_request_terminate_timeout' => 'request terminate timeout',
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $old = $this->site->phpFpmPoolSettings();
        $meta['php_fpm_pool'] = [
            'pm' => $validated['fpm_pm'],
            'max_children' => (int) $validated['fpm_max_children'],
            'max_requests' => (int) $validated['fpm_max_requests'],
            'request_terminate_timeout' => (int) $validated['fpm_request_terminate_timeout'],
        ];
        $this->site->meta = $meta;
        $this->site->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.php_fpm_pool_updated', $this->site, $old, $meta['php_fpm_pool']);
        }

        // The pool conf is rewritten + php-fpm reloaded as the first step of the
        // webserver apply (before the vhost), so the running pool picks up the
        // new sizing/timeout without a socket gap.
        if ($this->shouldAutoReapplyManagedWebserverConfig()) {
            ApplySiteWebserverConfigJob::dispatch($this->site->id);
            $this->toastSuccess(__('PHP-FPM pool saved. Webserver config queued.'));
        } else {
            $this->toastSuccess(__('PHP-FPM pool saved.'));
        }

        $this->syncFormFromSite();
    }
}
