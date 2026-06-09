<?php

$guides = require __DIR__.'/docs-vm-guides.php';

$serverSlugs = array_values(array_filter(
    array_keys($guides),
    static fn (string $slug): bool => str_starts_with($slug, 'server-'),
));
sort($serverSlugs);

$siteSlugs = array_values(array_filter(
    array_keys($guides),
    static fn (string $slug): bool => str_starts_with($slug, 'vm-site-'),
));
sort($siteSlugs);

return [
    'servers' => [
        'label' => 'Server workspace guides',
        'slugs' => $serverSlugs,
    ],
    'byo-sites' => [
        'label' => 'Site workspace guides',
        'slugs' => $siteSlugs,
    ],
];
