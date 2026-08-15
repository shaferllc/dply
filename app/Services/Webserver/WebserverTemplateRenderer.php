<?php

namespace App\Services\Webserver;

class WebserverTemplateRenderer
{
    /**
     * Substitute template placeholders with test values (syntax check / preview).
     *
     * @return array{content: string, replacements: array<string, string>}
     */
    public function substituteForTest(string $content): array
    {
        $map = [
            '{DOMAIN}' => 'example.test',
            '{SYSTEM_USER}' => 'dply',
            '{DIRECTORY}' => '/public',
            '{SOCKET}' => '/run/php/php8.3-fpm.sock',
        ];

        $out = str_replace(array_keys($map), array_values($map), $content);

        return [
            'content' => $out,
            'replacements' => $map,
        ];
    }

    /**
     * @param  array{domain?: string, system_user?: string, directory?: string, socket?: string}  $values
     */
    public function substitute(string $content, array $values = []): string
    {
        $defaults = [
            'domain' => 'example.test',
            'system_user' => 'dply',
            'directory' => '/public',
            'socket' => '/run/php/php8.3-fpm.sock',
        ];
        $v = array_merge($defaults, $values);

        return str_replace(
            ['{DOMAIN}', '{SYSTEM_USER}', '{DIRECTORY}', '{SOCKET}'],
            [$v['domain'], $v['system_user'], $v['directory'], $v['socket']],
            $content
        );
    }
}
