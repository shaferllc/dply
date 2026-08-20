<?php

namespace App\Modules\Serverless\Support;

/**
 * Vendor-neutral wording for Serverless errors and progress lines shown
 * to operators. Provider names stay on credential pickers; this only
 * rewrites display text that would otherwise name the functions host.
 */
final class ServerlessCustomerCopy
{
    /**
     * Longer phrases first so a short "DigitalOcean Functions" swap cannot
     * eat a more specific sentence.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'DigitalOcean API failed to create functions namespace:' => 'Could not create the functions namespace:',
        'Could not create the DigitalOcean Functions namespace' => 'Could not create the functions namespace',
        'Creating the DigitalOcean Functions namespace' => 'Creating the functions namespace',
        'DigitalOcean Functions namespace' => 'functions namespace',
        'DigitalOcean Functions rejected the credentials' => 'Functions rejected the credentials',
        'DigitalOcean Functions returned HTTP' => 'The functions host returned HTTP',
        'DigitalOcean Functions deploy failed' => 'Functions deploy failed',
        'DigitalOcean Functions deploy completed' => 'Functions deploy completed',
        'Uploading to DigitalOcean Functions' => 'Uploading to the functions host',
        'Uploaded to DigitalOcean Functions' => 'Uploaded to the functions host',
        'live from DigitalOcean Functions' => 'live from the functions host',
        'from DigitalOcean Functions' => 'from the functions host',
        'to DigitalOcean Functions' => 'to the functions host',
        'DigitalOcean Functions host' => 'functions host',
        'DigitalOcean created a namespace' => 'The functions host created a namespace',
        'check the DigitalOcean Functions response' => 'check the functions host response',
        'the DigitalOcean namespace' => 'the functions namespace',
        'the DigitalOcean console' => 'the functions host',
        'DigitalOcean Functions' => 'Functions',
    ];

    public static function neutralize(string $message): string
    {
        return str_replace(
            array_keys(self::REPLACEMENTS),
            array_values(self::REPLACEMENTS),
            $message,
        );
    }
}
