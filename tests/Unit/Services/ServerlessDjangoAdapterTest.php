<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessDjangoAdapterTest;
use RuntimeException;

use App\Services\Deploy\ServerlessDjangoAdapter;
use Illuminate\Support\Facades\File;
function repo(array $files): string
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
const WSGI = "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'myproject.settings')\napplication = get_wsgi_application()\n";
test('plan locates the django wsgi entrypoint', function () {
    $dir = repo([
        'manage.py' => "#!/usr/bin/env python\n",
        'myproject/wsgi.py' => WSGI,
    ]);

    try {
        $plan = (new ServerlessDjangoAdapter)->plan($dir);
        expect($plan['django'])->toBeTrue();
        expect($plan['module_file'])->toBe('myproject/wsgi.py');
        expect($plan['app_var'])->toBe('application');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject writes the adapter pointed at the wsgi module', function () {
    $dir = repo([
        'manage.py' => "#!/usr/bin/env python\n",
        'myproject/wsgi.py' => WSGI,
    ]);

    try {
        $result = (new ServerlessDjangoAdapter)->inject($dir);

        expect($result['ran'])->toBeTrue();
        expect($result['function'])->toBe('dplyMain');
        $adapter = File::get($dir.'/__main__.py');
        $this->assertStringContainsString('"myproject/wsgi.py"', $adapter);
        $this->assertStringContainsString('getattr(_module, "application")', $adapter);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject throws without a wsgi entrypoint', function () {
    $dir = repo(['manage.py' => "#!/usr/bin/env python\n"]);

    try {
        $this->expectException(RuntimeException::class);
        (new ServerlessDjangoAdapter)->inject($dir);
    } finally {
        File::deleteDirectory($dir);
    }
});
