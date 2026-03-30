<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\TaskDispatcher;

class ExecuteRemoteTaskOnServer
{
    public function __construct(
        private readonly TaskDispatcher $dispatcher,
    ) {}

    /**
     * Run a full bash script on the server (including shebang if desired).
     */
    public function runScript(
        Server $server,
        string $name,
        string $script,
        ?int $timeoutSeconds = null,
        bool $asRoot = false,
    ): ProcessOutput {
        $timeout = $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60);
        $task = AnonymousTask::script($name, $script, ['timeout' => $timeout]);
        $pending = $task->pending()
            ->onConnection($asRoot ? $server->connectionAsRoot() : $server->connectionAsUser())
            ->timeout($timeout);

        $output = $this->dispatcher->run($pending);
        if ($output === null) {
            throw new \RuntimeException("Remote task [{$name}] returned no output.");
        }

        return $output;
    }

    /**
     * Run inline bash statements under strict mode (shebang + set -euo pipefail prepended).
     */
    public function runInlineBash(
        Server $server,
        string $name,
        string $inlineBash,
        ?int $timeoutSeconds = null,
        bool $asRoot = false,
    ): ProcessOutput {
        $script = "#!/bin/bash\nset -euo pipefail\n".$inlineBash."\n";

        return $this->runScript($server, $name, $script, $timeoutSeconds, $asRoot);
    }
}
