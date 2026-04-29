<?php

$files = explode("\n", trim((string) shell_exec('rg -l "session\\(\\)->flash\\(\'success\'" app/Livewire')));
foreach ($files as $path) {
    if ($path === '' || ! is_file($path)) {
        continue;
    }
    $s = file_get_contents($path);
    $orig = $s;
    $s = preg_replace("/session\\(\\)->flash\\(\\'success\\',\\s*(.+?)\\);/s", '$this->toastSuccess($1);', $s);
    if ($s !== $orig) {
        file_put_contents($path, $s);
        echo $path."\n";
    }
}
