<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateApiClientCommand extends Command
{
    protected $signature = 'actions:generate-client
                            {--language=typescript : Output language (typescript, javascript, php)}
                            {--output= : Output file path}
                            {--action= : Generate client for specific action}';

    protected $description = 'Generate API client from actions';

    /**
     * Generate API client from actions.
     *
     * @example
     * // Generate TypeScript client
     * php artisan actions:generate-client --language=typescript --output=client.ts
     * @example
     * // Generate JavaScript client
     * php artisan actions:generate-client --language=javascript --output=client.js
     * @example
     * // Generate PHP client
     * php artisan actions:generate-client --language=php --output=ActionClient.php
     */
    public function handle(): int
    {
        $language = $this->option('language');
        $output = $this->option('output');
        $action = $this->option('action');

        if ($action) {
            $actions = collect([$action]);
        } else {
            $actions = ActionRegistry::discover();
        }

        $client = match ($language) {
            'typescript' => $this->generateTypeScriptClient($actions),
            'javascript' => $this->generateJavaScriptClient($actions),
            'php' => $this->generatePhpClient($actions),
            default => $this->generateTypeScriptClient($actions),
        };

        if ($output) {
            file_put_contents($output, $client);
            $this->info("Client written to: {$output}");
        } else {
            $this->line($client);
        }

        return Command::SUCCESS;
    }

    protected function generateTypeScriptClient($actions): string
    {
        $code = "// Auto-generated TypeScript client for Actions\n";
        $code .= '// Generated: '.now()->toDateTimeString()."\n\n";
        $code .= "export class ActionClient {\n";
        $code .= "    constructor(private baseUrl: string = '/api') {}\n\n";

        foreach ($actions as $actionClass) {
            $metadata = ActionRegistry::getMetadata($actionClass);
            $actionName = Str::camel($metadata['name']);

            $code .= "    async {$actionName}(...args: any[]): Promise<any> {\n";
            $code .= "        return fetch(`\${this.baseUrl}/actions/{$actionName}`, {\n";
            $code .= "            method: 'POST',\n";
            $code .= "            headers: { 'Content-Type': 'application/json' },\n";
            $code .= "            body: JSON.stringify({ arguments: args }),\n";
            $code .= "        }).then(res => res.json());\n";
            $code .= "    }\n\n";
        }

        $code .= "}\n";

        return $code;
    }

    protected function generateJavaScriptClient($actions): string
    {
        $code = "// Auto-generated JavaScript client for Actions\n";
        $code .= '// Generated: '.now()->toDateTimeString()."\n\n";
        $code .= "class ActionClient {\n";
        $code .= "    constructor(baseUrl = '/api') {\n";
        $code .= "        this.baseUrl = baseUrl;\n";
        $code .= "    }\n\n";

        foreach ($actions as $actionClass) {
            $metadata = ActionRegistry::getMetadata($actionClass);
            $actionName = Str::camel($metadata['name']);

            $code .= "    async {$actionName}(...args) {\n";
            $code .= "        return fetch(`\${this.baseUrl}/actions/{$actionName}`, {\n";
            $code .= "            method: 'POST',\n";
            $code .= "            headers: { 'Content-Type': 'application/json' },\n";
            $code .= "            body: JSON.stringify({ arguments: args }),\n";
            $code .= "        }).then(res => res.json());\n";
            $code .= "    }\n\n";
        }

        $code .= "}\n\n";
        $code .= "export default ActionClient;\n";

        return $code;
    }

    protected function generatePhpClient($actions): string
    {
        $code = "<?php\n\n";
        $code .= "declare(strict_types=1);\n\n";
        $code .= "// Auto-generated PHP client for Actions\n";
        $code .= '// Generated: '.now()->toDateTimeString()."\n\n";
        $code .= "class ActionClient\n";
        $code .= "{\n";
        $code .= "    public function __construct(\n";
        $code .= "        protected string \$baseUrl = '/api'\n";
        $code .= "    ) {}\n\n";

        foreach ($actions as $actionClass) {
            $metadata = ActionRegistry::getMetadata($actionClass);
            $actionName = Str::camel($metadata['name']);

            $code .= "    public function {$actionName}(...\$arguments): mixed\n";
            $code .= "    {\n";
            $code .= "        return app({$actionClass}::class)->handle(...\$arguments);\n";
            $code .= "    }\n\n";
        }

        $code .= "}\n";

        return $code;
    }
}
