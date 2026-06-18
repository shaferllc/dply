<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\AiCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `ai` binding type — an AI/LLM provider API key (OpenAI, Anthropic,
 * Gemini, Groq, Mistral). Pure config binding like error tracking: it injects
 * the provider's key env at deploy from a saved {@see AiCredential} or the
 * typed form.
 */
trait ManagesAiBindings
{
    /** Supported AI/LLM providers. */
    public const AI_PROVIDERS = ['openai', 'anthropic', 'gemini', 'groq', 'mistral'];

    /**
     * The API-key env var each provider expects.
     *
     * @var array<string, string>
     */
    public const AI_KEY_ENV = [
        'openai' => 'OPENAI_API_KEY',
        'anthropic' => 'ANTHROPIC_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
        'groq' => 'GROQ_API_KEY',
        'mistral' => 'MISTRAL_API_KEY',
    ];

    /**
     * @param  array<string, mixed> $params
     */
    private function attachAi(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::AI_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported AI provider.'));
        }

        $creds = $this->resolveAiCredentials($site, $provider, $params);
        if (($creds['api_key'] ?? '') === '') {
            throw new InvalidArgumentException(__('An API key is required.'));
        }

        $binding = $this->persist($site, 'ai', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->aiLabel($provider),
            'target_type' => 'ai',
            'target_id' => null,
            'injected_env' => $this->aiEnv($provider, $creds),
            'config' => ['provider' => $provider],
            'last_error' => null,
        ]);

        $this->maybeSaveAiCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolveAiCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = AiCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof AiCredential) {
                throw new InvalidArgumentException(__('That saved AI credential is no longer available.'));
            }

            return ($cred->credentials );
        }

        return array_filter([
            'api_key' => trim((string) ($params['api_key'] ?? '')),
            'organization' => trim((string) ($params['organization'] ?? '')),
        ], fn ($v) => $v !== '');
    }

    /**
     * @param  array<string, mixed> $creds
     * @return array<string, string>
     */
    private function aiEnv(string $provider, array $creds): array
    {
        $env = [self::AI_KEY_ENV[$provider] => (string) ($creds['api_key'] ?? '')];

        // OpenAI optionally carries an organization id.
        if ($provider === 'openai' && ($creds['organization'] ?? '') !== '') {
            $env['OPENAI_ORGANIZATION'] = (string) $creds['organization'];
        }

        return $env;
    }

    private function aiLabel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'gemini' => 'Google Gemini',
            'groq' => 'Groq',
            'mistral' => 'Mistral',
            default => ucfirst($provider),
        };
    }

    /**
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
     */
    private function maybeSaveAiCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if (($creds['api_key'] ?? '') === '') {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $name = $this->aiLabel($provider).' '.__('key');
        }

        AiCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
