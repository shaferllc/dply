<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$server = App\Models\Server::factory()->make();
$cat = app(App\Services\Servers\ServerConfigFileCatalog::class);
$m = new ReflectionMethod($cat, 'collectDiscoveryProbes');
$m->setAccessible(true);
$probes = $m->invoke($cat, $server, null);

foreach ($probes as $i => $p) {
    echo $i.': '.($p['kind'] ?? '').'/'.($p['group'] ?? '').' '.($p['engine'] ?? '').' '.json_encode($p['patterns'] ?? [])."\n";
}
