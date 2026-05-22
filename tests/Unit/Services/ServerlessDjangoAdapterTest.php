<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessDjangoAdapter;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ServerlessDjangoAdapterTest extends TestCase
{
    private function repo(array $files): string
    {
        $dir = storage_path('framework/testing/django-adapter-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        foreach ($files as $name => $contents) {
            $path = $dir.'/'.$name;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }

        return $dir;
    }

    private const WSGI = "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'myproject.settings')\napplication = get_wsgi_application()\n";

    public function test_plan_locates_the_django_wsgi_entrypoint(): void
    {
        $dir = $this->repo([
            'manage.py' => "#!/usr/bin/env python\n",
            'myproject/wsgi.py' => self::WSGI,
        ]);

        try {
            $plan = (new ServerlessDjangoAdapter)->plan($dir);
            $this->assertTrue($plan['django']);
            $this->assertSame('myproject/wsgi.py', $plan['module_file']);
            $this->assertSame('application', $plan['app_var']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_writes_the_adapter_pointed_at_the_wsgi_module(): void
    {
        $dir = $this->repo([
            'manage.py' => "#!/usr/bin/env python\n",
            'myproject/wsgi.py' => self::WSGI,
        ]);

        try {
            $result = (new ServerlessDjangoAdapter)->inject($dir);

            $this->assertTrue($result['ran']);
            $this->assertSame('dplyMain', $result['function']);
            $adapter = File::get($dir.'/__main__.py');
            $this->assertStringContainsString('"myproject/wsgi.py"', $adapter);
            $this->assertStringContainsString('getattr(_module, "application")', $adapter);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_throws_without_a_wsgi_entrypoint(): void
    {
        $dir = $this->repo(['manage.py' => "#!/usr/bin/env python\n"]);

        try {
            $this->expectException(RuntimeException::class);
            (new ServerlessDjangoAdapter)->inject($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
