<x-task-shell-defaults />

ssh-keygen -t rsa -b {{ $bits }} -C "{{ $comment() }}" -f {{ $privatePath }} -N ""

echo "{{ \App\Modules\TaskRunner\Helper::eof() }}"
