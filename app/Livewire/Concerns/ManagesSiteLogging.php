<?php

namespace App\Livewire\Concerns;

use App\Services\Deploy\SiteBindingManager;
use App\Services\Logging\LoggingChannelCatalog;
use App\Services\Logging\LoggingConfigGenerator;
use App\Services\Logging\LoggingSpec;
use App\Services\Logging\LoggingSpecValidator;
use Illuminate\Support\Facades\Gate;

/**
 * The Phase 3 logging editor: drives the Logs-tab card where the operator
 * assembles the site's full `config/logging.php` (channels + default + stack +
 * deprecations) that dply generates and owns. Structure lives in
 * {@see $loggingSpec}; secret leaf values are held transiently in
 * {@see $loggingSecrets} (never hydrated back from storage) and handed to
 * {@see SiteBindingManager::saveLoggingSpec()} on save.
 *
 * @property \App\Models\Site $site
 */
trait ManagesSiteLogging
{
    /** @var array<string, mixed> The working logging spec. */
    public array $loggingSpec = [];

    /** @var array<string, array<string, string>> Transient per-channel secret inputs ([name][field] => value). */
    public array $loggingSecrets = [];

    public string $loggingPreviewContent = '';

    public bool $showLoggingPreview = false;

    protected bool $loggingSpecLoaded = false;

    /**
     * Lazily populate the working spec from the site's logging binding (or a
     * blank default). Idempotent — safe to call from the blade and from every
     * editor action. Secret values are never hydrated back.
     */
    public function hydrateLoggingSpec(): void
    {
        if ($this->loggingSpecLoaded) {
            return;
        }

        $binding = $this->site->bindings->firstWhere('type', 'logging');
        $config = ($binding && is_array($binding->config)) ? $binding->config : [];

        if (LoggingSpec::isV2($config)) {
            unset($config['provider']); // drop the transitional legacy key
            $this->loggingSpec = $config;
        } else {
            $this->loggingSpec = $this->blankLoggingSpec();
        }

        $this->loggingSecrets = [];
        $this->loggingSpecLoaded = true;
    }

    public function addLoggingChannel(string $type): void
    {
        Gate::authorize('update', $this->site);
        if (! LoggingChannelCatalog::exists($type)) {
            return;
        }

        $this->hydrateLoggingSpec();

        $base = $this->channelBaseName($type);
        $name = $this->uniqueChannelName($base);

        $extra = [];
        if (LoggingChannelCatalog::supportsFormat($type)) {
            $extra['format'] = 'line';
        }
        if ($type === LoggingChannelCatalog::FILE_DAILY) {
            $extra['days'] = 14;
        }
        if ($type === LoggingChannelCatalog::CUSTOM_MONOLOG) {
            $extra += ['handler' => '', 'handler_with' => [], 'formatter' => '', 'processors' => []];
        }
        if ($type === LoggingChannelCatalog::SLACK) {
            $extra += ['username' => 'Laravel Log', 'emoji' => ':boom:'];
        }
        if ($type === LoggingChannelCatalog::SYSLOG) {
            $extra['facility'] = 'LOG_USER';
        }

        $this->loggingSpec['channels'][] = LoggingSpec::channel($name, $type, $extra);

        // First channel added to an empty spec becomes the default.
        if (count($this->loggingSpec['channels']) === 1) {
            $this->loggingSpec['default'] = $name;
        }
    }

    public function removeLoggingChannel(string $name): void
    {
        Gate::authorize('update', $this->site);
        $this->hydrateLoggingSpec();

        $this->loggingSpec['channels'] = array_values(array_filter(
            $this->loggingSpec['channels'] ?? [],
            fn ($c) => ($c['name'] ?? null) !== $name,
        ));
        $this->loggingSpec['stack'] = array_values(array_filter(
            $this->loggingSpec['stack'] ?? [],
            fn ($n) => $n !== $name,
        ));
        unset($this->loggingSecrets[$name]);

        // Repoint the default if it pointed at the removed channel.
        if (($this->loggingSpec['default'] ?? null) === $name) {
            $first = $this->loggingSpec['channels'][0]['name'] ?? 'single';
            $this->loggingSpec['default'] = ($this->loggingSpec['stack'] ?? []) !== [] ? 'stack' : $first;
        }
    }

    public function setLoggingDefault(string $name): void
    {
        $this->hydrateLoggingSpec();
        $this->loggingSpec['default'] = $name;
    }

    public function toggleLoggingStackMember(string $name): void
    {
        $this->hydrateLoggingSpec();
        $stack = array_values($this->loggingSpec['stack'] ?? []);
        if (in_array($name, $stack, true)) {
            $stack = array_values(array_filter($stack, fn ($n) => $n !== $name));
        } else {
            $stack[] = $name;
        }
        $this->loggingSpec['stack'] = $stack;
    }

    public function previewLoggingConfig(): void
    {
        $this->hydrateLoggingSpec();
        try {
            (new LoggingSpecValidator)->validate($this->loggingSpec);
            $this->loggingPreviewContent = app(LoggingConfigGenerator::class)->generate($this->loggingSpec);
            $this->showLoggingPreview = true;
        } catch (\Throwable $e) {
            $this->showLoggingPreview = false;
            $this->dispatchLoggingError($e->getMessage());
        }
    }

    public function saveLoggingSpec(SiteBindingManager $manager): void
    {
        Gate::authorize('update', $this->site);
        $this->hydrateLoggingSpec();

        try {
            $manager->saveLoggingSpec($this->site, $this->loggingSpec, $this->loggingSecrets);
        } catch (\Throwable $e) {
            $this->dispatchLoggingError($e->getMessage());

            return;
        }

        $this->loggingSecrets = [];
        $this->showLoggingPreview = false;
        $this->loggingSpecLoaded = false; // force re-hydrate from the saved binding
        $this->site->load('bindings');

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Logging configuration saved. It takes effect on the next deploy.'));
        }
    }

    /**
     * Channel types available to add, with labels and escape-hatch/system flags,
     * for the "Add channel" menu.
     *
     * @return array<int, array{type: string, label: string, escape: bool}>
     */
    public function loggingChannelTypeOptions(): array
    {
        $out = [];
        foreach (LoggingChannelCatalog::types() as $type => $meta) {
            $out[] = [
                'type' => $type,
                'label' => (string) $meta['label'],
                'escape' => (bool) ($meta['is_escape_hatch'] ?? false),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function blankLoggingSpec(): array
    {
        return [
            'version' => LoggingSpec::VERSION,
            'default' => 'single',
            'stack' => [],
            'deprecations' => ['channel' => 'null', 'trace' => false],
            'channels' => [LoggingSpec::channel('single', LoggingChannelCatalog::FILE_SINGLE, ['format' => 'line'])],
        ];
    }

    private function channelBaseName(string $type): string
    {
        return match ($type) {
            LoggingChannelCatalog::FILE_SINGLE => 'single',
            LoggingChannelCatalog::FILE_DAILY => 'daily',
            LoggingChannelCatalog::CUSTOM_MONOLOG => 'custom',
            default => $type,
        };
    }

    private function uniqueChannelName(string $base): string
    {
        $existing = array_column($this->loggingSpec['channels'] ?? [], 'name');
        if (! in_array($base, $existing, true)) {
            return $base;
        }
        $i = 2;
        while (in_array($base.'_'.$i, $existing, true)) {
            $i++;
        }

        return $base.'_'.$i;
    }

    private function dispatchLoggingError(string $message): void
    {
        if (method_exists($this, 'toastError')) {
            $this->toastError($message);

            return;
        }
        $this->addError('loggingSpec', $message);
    }
}
