<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;

class DplyOAuthClientsSeeder extends Seeder
{
    /**
     * Seed first-party OAuth2 clients for each dply product app (authorization code + refresh).
     *
     * Redirect URIs must match each product’s `/oauth/callback` (or your chosen path) exactly.
     */
    public function run(): void
    {
        $clients = app(ClientRepository::class);

        $definitions = [
            [
                'name' => 'dply-byo',
                'redirect_uris' => array_filter([
                    env('DPLY_OAUTH_REDIRECT_BYO', 'https://dply.io/oauth/callback'),
                    'http://dply.test/oauth/callback',
                ]),
            ],
            [
                'name' => 'dply-cloud',
                'redirect_uris' => array_filter([
                    env('DPLY_OAUTH_REDIRECT_CLOUD', 'https://cloud.dply.io/oauth/callback'),
                    'http://dply-cloud.test/oauth/callback',
                ]),
            ],
            [
                'name' => 'dply-wordpress',
                'redirect_uris' => array_filter([
                    env('DPLY_OAUTH_REDIRECT_WORDPRESS', 'https://wp.dply.io/oauth/callback'),
                    'http://dply-wordpress.test/oauth/callback',
                ]),
            ],
            [
                'name' => 'dply-edge',
                'redirect_uris' => array_filter([
                    env('DPLY_OAUTH_REDIRECT_EDGE', 'https://edge.dply.io/oauth/callback'),
                    'http://dply-edge.test/oauth/callback',
                ]),
            ],
        ];

        foreach ($definitions as $def) {
            if ($def['redirect_uris'] === []) {
                continue;
            }

            $clients->createAuthorizationCodeGrantClient(
                $def['name'],
                array_values($def['redirect_uris']),
                confidential: true,
            );
        }
    }
}
