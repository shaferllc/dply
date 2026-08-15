<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Modules\Notifications\Services\PagerDutyClient;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Shared rules / attributes / config-blob assembly for the PagerDuty channel
 * type, used by all three surfaces that can create one. Twin of
 * {@see BuildsIntercomChannelInput} — see that trait for why this is centralised
 * rather than copied into each surface's match arms.
 *
 * @phpstan-require-extends Component
 */
trait BuildsPagerDutyChannelInput
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function pagerDutyValidationRules(string $prefix): array
    {
        return [
            // Events API v2 integration keys are 32-char hex, but PagerDuty has
            // never promised that, so this stays a length bound rather than a
            // format rule that would reject a valid future key.
            $prefix.'pagerduty_routing_key' => ['required', 'string', 'max:255'],
            $prefix.'pagerduty_region' => ['required', 'string', Rule::in(PagerDutyClient::regions())],
            $prefix.'pagerduty_default_severity' => ['required', 'string', Rule::in(PagerDutyMessage::severities())],
            $prefix.'pagerduty_source' => ['nullable', 'string', 'max:255'],
            $prefix.'pagerduty_component' => ['nullable', 'string', 'max:255'],
            $prefix.'pagerduty_group' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function pagerDutyValidationAttributes(string $prefix): array
    {
        return [
            $prefix.'pagerduty_routing_key' => __('integration key'),
            $prefix.'pagerduty_region' => __('region'),
            $prefix.'pagerduty_default_severity' => __('default severity'),
            $prefix.'pagerduty_source' => __('source'),
            $prefix.'pagerduty_component' => __('component'),
            $prefix.'pagerduty_group' => __('group'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pagerDutyConfigFromInput(string $prefix): array
    {
        return [
            'routing_key' => (string) $this->{$prefix.'pagerduty_routing_key'},
            'region' => PagerDutyClient::normalizeRegion((string) $this->{$prefix.'pagerduty_region'}),
            // Only used when an alert arrives without a severity of its own;
            // events routed through the subscription matrix carry their own.
            'default_severity' => (string) $this->{$prefix.'pagerduty_default_severity'},
            'source' => ((string) $this->{$prefix.'pagerduty_source'}) ?: null,
            'component' => ((string) $this->{$prefix.'pagerduty_component'}) ?: null,
            'group' => ((string) $this->{$prefix.'pagerduty_group'}) ?: null,
        ];
    }
}
