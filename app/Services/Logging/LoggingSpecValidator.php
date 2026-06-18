<?php

namespace App\Services\Logging;

use InvalidArgumentException;

/**
 * Validates a logging spec before it is persisted — the first of the three
 * Q9 safety layers (the others are `php -l` + the deploy resolution probe).
 * It catches every curated-path mistake (unknown type, bad level, dangling
 * stack reference, missing required field) so they never reach a deploy. It
 * deliberately cannot verify that an escape-hatch handler class *exists* —
 * that's the probe's job — but it does reject class names that aren't even
 * syntactically plausible.
 *
 * Throws {@see InvalidArgumentException} with a human message on the first
 * problem; the Livewire layer surfaces it as a toast.
 */
final class LoggingSpecValidator
{
    /**
     * @param  array<string, mixed> $spec
     */
    public function validate(array $spec): void
    {
        $channels = $spec['channels'] ?? null;
        if (! is_array($channels) || $channels === []) {
            throw new InvalidArgumentException(__('Add at least one log channel.'));
        }

        $names = [];
        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                throw new InvalidArgumentException(__('Malformed channel entry.'));
            }
            $name = $this->channelName($channel);
            if (isset($names[$name])) {
                throw new InvalidArgumentException(__('Duplicate channel name “:name”.', ['name' => $name]));
            }
            $names[$name] = true;
            $this->validateChannel($channel, $name);
        }

        $this->validateStack($spec, $names);
        $this->validateDefault($spec, $names);
        $this->validateDeprecations($spec, $names);
    }

    /**
     * @param  array<string, mixed> $channel
     */
    private function channelName(array $channel): string
    {
        $name = trim((string) ($channel['name'] ?? ''));
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(__('Channel name “:name” must be lowercase letters, numbers and underscores.', ['name' => $name]));
        }
        if ($name === 'stack') {
            throw new InvalidArgumentException(__('“stack” is reserved — name the channel something else.'));
        }
        if (in_array($name, ['null', 'emergency'], true)) {
            throw new InvalidArgumentException(__('“:name” is a reserved baseline channel.', ['name' => $name]));
        }

        return $name;
    }

    /**
     * @param  array<string, mixed> $channel
     */
    private function validateChannel(array $channel, string $name): void
    {
        $type = (string) ($channel['type'] ?? '');
        if (! LoggingChannelCatalog::exists($type)) {
            throw new InvalidArgumentException(__('Unknown channel type for “:name”.', ['name' => $name]));
        }

        $meta = LoggingChannelCatalog::get($type) ?? [];

        $level = strtolower(trim((string) ($channel['level'] ?? 'debug')));
        if (($meta['supports_level'] ?? false) && ! in_array($level, LoggingChannelCatalog::LEVELS, true)) {
            throw new InvalidArgumentException(__('Invalid level “:level” on “:name”.', ['level' => $level, 'name' => $name]));
        }

        if (($meta['supports_format'] ?? false)) {
            $format = (string) ($channel['format'] ?? 'line');
            if (! in_array($format, ['line', 'json'], true)) {
                throw new InvalidArgumentException(__('Format must be line or json on “:name”.', ['name' => $name]));
            }
        }

        if ($type === LoggingChannelCatalog::FILE_DAILY) {
            $days = (int) ($channel['days'] ?? 0);
            if ($days < 1 || $days > 365) {
                throw new InvalidArgumentException(__('Retention days on “:name” must be between 1 and 365.', ['name' => $name]));
            }
        }

        if ($type === LoggingChannelCatalog::CUSTOM_MONOLOG) {
            $this->validateCustom($channel, $name);
        }

        // Every declared secret field must have a usable env key.
        $env = is_array($channel['env'] ?? null) ? $channel['env'] : [];
        foreach (LoggingChannelCatalog::secretFields($type) as $field) {
            $key = strtoupper(trim((string) ($env[$field] ?? '')));
            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException(__('“:name” is missing its env binding for :field.', ['name' => $name, 'field' => $field]));
            }
        }
    }

    /**
     * @param  array<string, mixed> $channel
     */
    private function validateCustom(array $channel, string $name): void
    {
        $handler = trim((string) ($channel['handler'] ?? ''));
        if ($handler === '') {
            throw new InvalidArgumentException(__('Custom channel “:name” needs a handler class.', ['name' => $name]));
        }
        foreach (['handler' => $handler, 'formatter' => trim((string) ($channel['formatter'] ?? ''))] as $what => $fqcn) {
            if ($fqcn === '') {
                continue;
            }
            if (! preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $fqcn)) {
                throw new InvalidArgumentException(__('“:name” has an invalid :what class name.', ['name' => $name, 'what' => $what]));
            }
        }
        foreach ((array) ($channel['processors'] ?? []) as $p) {
            $p = trim((string) $p);
            if ($p !== '' && ! preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $p)) {
                throw new InvalidArgumentException(__('“:name” has an invalid processor class name.', ['name' => $name]));
            }
        }
    }

    /**
     * @param  array<string, mixed> $spec
     * @param  array<string, mixed> $names
     */
    private function validateStack(array $spec, array $names): void
    {
        foreach ((array) ($spec['stack'] ?? []) as $member) {
            $member = (string) $member;
            if (! isset($names[$member])) {
                throw new InvalidArgumentException(__('Stack references unknown channel “:name”.', ['name' => $member]));
            }
        }
    }

    /**
     * @param  array<string, mixed> $spec
     * @param  array<string, mixed> $names
     */
    private function validateDefault(array $spec, array $names): void
    {
        $default = (string) ($spec['default'] ?? '');
        if ($default === '') {
            throw new InvalidArgumentException(__('Choose a default log channel.'));
        }
        $stack = array_filter(array_map('strval', (array) ($spec['stack'] ?? [])));
        if ($default === 'stack') {
            if ($stack === []) {
                throw new InvalidArgumentException(__('Default is “stack” but the stack is empty.'));
            }

            return;
        }
        if (! isset($names[$default])) {
            throw new InvalidArgumentException(__('Default references unknown channel “:name”.', ['name' => $default]));
        }
    }

    /**
     * @param  array<string, mixed> $spec
     * @param  array<string, mixed> $names
     */
    private function validateDeprecations(array $spec, array $names): void
    {
        $channel = trim((string) ($spec['deprecations']['channel'] ?? 'null'));
        if ($channel === '' || $channel === 'null' || $channel === 'stack') {
            return;
        }
        if (! isset($names[$channel])) {
            throw new InvalidArgumentException(__('Deprecations channel “:name” is not defined.', ['name' => $channel]));
        }
    }
}
