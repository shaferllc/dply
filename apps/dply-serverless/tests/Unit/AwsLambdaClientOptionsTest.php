<?php

namespace Tests\Unit;

use App\Serverless\Aws\AwsLambdaClientOptions;
use PHPUnit\Framework\TestCase;

class AwsLambdaClientOptionsTest extends TestCase
{
    public function test_empty_config_matches_default_region_without_static_credentials(): void
    {
        $r = AwsLambdaClientOptions::resolve('eu-west-1', []);

        $this->assertTrue($r['equals_default']);
        $this->assertSame('eu-west-1', $r['client_config']['region']);
        $this->assertArrayNotHasKey('credentials', $r['client_config']);
    }

    public function test_aws_region_in_project_settings_overrides_default(): void
    {
        $r = AwsLambdaClientOptions::resolve('us-east-1', [
            'project' => ['settings' => ['aws_region' => 'ap-southeast-2']],
        ]);

        $this->assertFalse($r['equals_default']);
        $this->assertSame('ap-southeast-2', $r['client_config']['region']);
    }

    public function test_static_access_keys_trigger_non_default_even_when_region_matches(): void
    {
        $r = AwsLambdaClientOptions::resolve('us-east-1', [
            'credentials' => [
                'access_key_id' => 'AKIA123',
                'secret_access_key' => 'secret',
            ],
        ]);

        $this->assertFalse($r['equals_default']);
        $this->assertSame('us-east-1', $r['client_config']['region']);
        $this->assertSame([
            'key' => 'AKIA123',
            'secret' => 'secret',
        ], $r['client_config']['credentials']);
    }

    public function test_accepts_aws_prefixed_key_aliases_and_session_token(): void
    {
        $r = AwsLambdaClientOptions::resolve('us-east-1', [
            'credentials' => [
                'aws_access_key_id' => 'KEY',
                'aws_secret_access_key' => 'SEC',
                'aws_session_token' => 'TOK',
            ],
        ]);

        $this->assertSame([
            'key' => 'KEY',
            'secret' => 'SEC',
            'token' => 'TOK',
        ], $r['client_config']['credentials']);
    }
}
