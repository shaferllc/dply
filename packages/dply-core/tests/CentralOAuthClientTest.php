<?php

declare(strict_types=1);

namespace Dply\Core\Tests;

use Dply\Core\Auth\CentralOAuthClient;
use Dply\Core\Auth\CentralOAuthException;
use Dply\Core\Auth\OAuthPkce;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class CentralOAuthClientTest extends TestCase
{
    public function test_authorization_url_includes_pkce_and_scope(): void
    {
        $c = new CentralOAuthClient(
            'http://auth.test',
            'cid',
            'sec',
            'http://app.test/cb',
        );

        $v = OAuthPkce::generateCodeVerifier(64);
        $ch = OAuthPkce::codeChallengeS256($v);
        $url = $c->authorizationUrl('state-xyz', $ch);

        $this->assertStringStartsWith('http://auth.test/oauth/authorize?', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringContainsString('redirect_uri='.rawurlencode('http://app.test/cb'), $url);
        $this->assertStringContainsString('scope=read-user', $url);
        $this->assertStringContainsString('state=state-xyz', $url);
        $this->assertStringContainsString('code_challenge='.rawurlencode($ch), $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function test_exchange_authorization_code_returns_access_token(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"access_token":"atok","token_type":"Bearer","expires_in":3600}'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $c = new CentralOAuthClient('http://auth.test', 'cid', 'sec', 'http://app.test/cb', $client);

        $out = $c->exchangeAuthorizationCode('the-code', 'verifier');

        $this->assertSame('atok', $out['access_token']);
    }

    public function test_exchange_authorization_code_throws_on_error_json(): void
    {
        $mock = new MockHandler([
            new Response(400, [], '{"error":"invalid_grant","error_description":"bad"}'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $c = new CentralOAuthClient('http://auth.test', 'cid', 'sec', 'http://app.test/cb', $client);

        $this->expectException(CentralOAuthException::class);
        $c->exchangeAuthorizationCode('x', 'y');
    }

    public function test_fetch_user_profile_returns_array(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"id":1,"name":"A","email":"a@b.com","email_verified_at":"2020-01-01T00:00:00+00:00"}'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $c = new CentralOAuthClient('http://auth.test', 'cid', 'sec', 'http://app.test/cb', $client);

        $u = $c->fetchUserProfile('token');

        $this->assertSame('1', (string) $u['id']);
        $this->assertSame('A', $u['name']);
        $this->assertSame('a@b.com', $u['email']);
        $this->assertSame('2020-01-01T00:00:00+00:00', $u['email_verified_at']);
    }
}
