<?php

namespace App\Services\Wordpress\Validation;

use App\Models\WordpressProject;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Validates settings for hosted-only WordPress projects (ADR-007).
 */
final class HostedWordpressProjectSettingsValidator
{
    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function validateForApi(?array $settings): void
    {
        if ($settings === null || $settings === []) {
            return;
        }

        $runtime = $settings['runtime'] ?? null;
        if ($runtime !== null && $runtime !== '' && $runtime !== 'hosted') {
            $this->fail('settings.runtime must be "hosted" when set.');
        }

        if (isset($settings['primary_url']) && $settings['primary_url'] !== null && $settings['primary_url'] !== '') {
            $url = (string) $settings['primary_url'];
            if (strlen($url) > 2048) {
                $this->fail('settings.primary_url is too long.');
            }
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $this->fail('settings.primary_url must be a valid URL.');
            }
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $this->fail('settings.primary_url must use http or https.');
            }
        }

        foreach (['environment_id', 'compute_ref', 'data_ref'] as $key) {
            if (! isset($settings[$key]) || $settings[$key] === null || $settings[$key] === '') {
                continue;
            }
            $s = (string) $settings[$key];
            $max = $key === 'compute_ref' || $key === 'data_ref' ? 256 : 128;
            if (strlen($s) > $max) {
                $this->fail("settings.{$key} exceeds maximum length ({$max}).");
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'settings' => [$message],
        ]);
    }

    public function assertDeployable(WordpressProject $project): void
    {
        $settings = is_array($project->settings) ? $project->settings : [];
        $runtime = $settings['runtime'] ?? null;
        if ($runtime !== null && $runtime !== '' && $runtime !== 'hosted') {
            throw new InvalidArgumentException('Only hosted runtime projects can be deployed.');
        }

        $envId = isset($settings['environment_id']) ? trim((string) $settings['environment_id']) : '';
        $primaryUrl = isset($settings['primary_url']) ? trim((string) $settings['primary_url']) : '';

        if ($envId === '' && $primaryUrl === '') {
            throw new InvalidArgumentException(
                'Hosted project requires settings.environment_id or settings.primary_url before deploy.'
            );
        }
    }
}
