<x-task-runner::task-shell-defaults />

(umask 077 ; touch {{ $file_name }}; chmod 775)
