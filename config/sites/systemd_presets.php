<?php

/**
 * Presets for the site-scoped systemd Services workspace.
 *
 * Laravel queue workers (Horizon, queue:work) and Rails Sidekiq via Supervisor
 * live on Daemons — not duplicated here.
 */
return [
    'node-worker' => [
        'label' => 'Node worker',
        'type' => 'worker',
        'name' => 'worker',
        'command' => 'node worker.js',
    ],
    'sidekiq' => [
        'label' => 'Sidekiq (systemd)',
        'type' => 'worker',
        'name' => 'sidekiq',
        'command' => 'bundle exec sidekiq -C config/sidekiq.yml',
    ],
    'celery' => [
        'label' => 'Celery worker',
        'type' => 'worker',
        'name' => 'celery',
        'command' => 'celery -A app worker -l info',
    ],
    'python-scheduler' => [
        'label' => 'Python scheduler',
        'type' => 'scheduler',
        'name' => 'scheduler',
        'command' => 'python manage.py run_scheduler',
    ],
];
