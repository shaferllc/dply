<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Contracts;

/**
 * The capability vocabulary every serverless backend answers against.
 *
 * DigitalOcean Functions is the reference implementation — each case maps to
 * a documented Functions capability — but the enum is deliberately provider
 * neutral so Lambda/Workers/Netlify/Vercel can declare the same surface and
 * the workspace UI can gate on the answer rather than on a host kind.
 *
 * A backend declares its set via {@see DeclaresServerlessFeatures}; the shell
 * asks {@see \App\Modules\Serverless\Services\ServerlessFeatureMatrix}.
 */
enum ServerlessFeature: string
{
    /** The function is reachable over HTTP at all (OpenWhisk `web-export`). */
    case WebFunction = 'web_function';

    /**
     * The handler receives the untransformed request — body as a base64
     * string plus the raw query string (OpenWhisk `raw-http`) — instead of
     * the platform parsing it into named parameters.
     */
    case RawHttp = 'raw_http';

    /**
     * The function answers its own OPTIONS preflight and sets its own CORS
     * response headers (OpenWhisk `web-custom-options`) rather than taking
     * the platform's permissive defaults.
     */
    case CustomCors = 'custom_cors';

    /**
     * The web endpoint requires a shared secret in `X-Require-Whisk-Auth`
     * (OpenWhisk `require-whisk-auth`), so the URL alone is not a credential.
     */
    case SecuredWeb = 'secured_web';

    /** The platform's own auth key is passed to the handler (`provide-api-key`). */
    case ApiKeyPassthrough = 'api_key_passthrough';

    /** Default parameters can be bound to the function at deploy time. */
    case DefaultParameters = 'default_parameters';

    /**
     * Bound parameters can be sealed so a caller cannot override them per
     * request (OpenWhisk `final`) — the safety catch on secrets bound as
     * default parameters.
     */
    case FinalParameters = 'final_parameters';

    /** The function can be invoked without waiting for its result. */
    case AsyncInvocation = 'async_invocation';

    /** A completed invocation can be fetched back as a full activation record. */
    case ActivationRecords = 'activation_records';

    /** Cron-scheduled invocation. */
    case ScheduledTriggers = 'scheduled_triggers';

    /** Codeless compositions chaining several functions. */
    case Sequences = 'sequences';

    /** One repository can deploy many functions, grouped into packages. */
    case MultiActionProjects = 'multi_action_projects';

    /** A repo-level manifest (`project.yml`) configures the deployment. */
    case ProjectManifest = 'project_manifest';

    /** Per-namespace credentials can be minted and revoked. */
    case NamespaceAccessKeys = 'namespace_access_keys';

    /** Function logs can be forwarded to a third-party logging service. */
    case LogForwarding = 'log_forwarding';

    public function label(): string
    {
        return match ($this) {
            self::WebFunction => __('Web function'),
            self::RawHttp => __('Raw HTTP handling'),
            self::CustomCors => __('Custom CORS headers'),
            self::SecuredWeb => __('Secured web endpoint'),
            self::ApiKeyPassthrough => __('API key passthrough'),
            self::DefaultParameters => __('Default parameters'),
            self::FinalParameters => __('Sealed parameters'),
            self::AsyncInvocation => __('Asynchronous invocation'),
            self::ActivationRecords => __('Activation records'),
            self::ScheduledTriggers => __('Scheduled triggers'),
            self::Sequences => __('Sequences'),
            self::MultiActionProjects => __('Multi-function projects'),
            self::ProjectManifest => __('Project manifest'),
            self::NamespaceAccessKeys => __('Namespace access keys'),
            self::LogForwarding => __('Log forwarding'),
        };
    }

    /**
     * Every feature, in the order the workspace lists them.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
