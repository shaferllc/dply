<?php

namespace App\Services\Webserver;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class NginxConfigSyntaxTester
{
    /**
     * Validate nginx config syntax by wrapping a server block in a minimal main config and running nginx -t.
     *
     * @return array{ok: bool, message: string}
     */
    public function testServerBlock(string $serverBlock): array
    {
        $banner = (string) config('webserver_templates.required_banner_line', '');
        if ($banner !== '' && ! str_contains($serverBlock, $banner)) {
            return [
                'ok' => false,
                'message' => __('Template must include this line: :line', ['line' => $banner]),
            ];
        }

        $serverBlock = trim($serverBlock);
        if ($serverBlock === '') {
            return ['ok' => false, 'message' => __('Template content is empty.')];
        }

        $pidFile = sys_get_temp_dir().'/dply-nginx-test-'.Str::random(8).'.pid';
        $errLog = sys_get_temp_dir().'/dply-nginx-test-'.Str::random(8).'.log';

        $wrapped = <<<NGINX
pid {$pidFile};
error_log {$errLog};
events {}
http {
{$serverBlock}
}
NGINX;

        $path = sys_get_temp_dir().'/dply-nginx-test-'.Str::random(12).'.conf';

        try {
            file_put_contents($path, $wrapped);

            $result = Process::timeout(15)->run(['nginx', '-t', '-c', $path]);

            $out = trim($result->errorOutput().' '.$result->output());

            if ($result->successful()) {
                return [
                    'ok' => true,
                    'message' => $out !== '' ? $out : __('Nginx configuration syntax is valid.'),
                ];
            }

            return [
                'ok' => false,
                'message' => $out !== '' ? $out : __('nginx -t failed with exit code :code.', ['code' => $result->exitCode()]),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => __('Could not run nginx -t (:msg). Install nginx locally or validate on a server.', ['msg' => $e->getMessage()]),
            ];
        } finally {
            @unlink($path);
            @unlink($pidFile);
            @unlink($errLog);
        }
    }
}
