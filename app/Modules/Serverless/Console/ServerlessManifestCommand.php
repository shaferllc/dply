<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessProjectManifestWriter;
use Illuminate\Console\Command;

/**
 * Print (or write) the `project.yml` describing a serverless Site.
 *
 * The manifest dply *reads* from a repository is honoured at deploy time;
 * this emits the same document in the other direction, so a function's
 * configuration can be committed to the repo it belongs to and deployed by
 * `doctl serverless deploy` — or reviewed in a pull request — rather than
 * living only in dply.
 *
 * Parameter values are deliberately emitted as `${NAME}` references, so the
 * output is safe to commit.
 */
class ServerlessManifestCommand extends Command
{
    protected $signature = 'serverless:manifest
        {site : Site id or slug}
        {--write= : Write to this path instead of printing}';

    protected $description = 'Print the project.yml manifest for a serverless site.';

    public function handle(ServerlessProjectManifestWriter $writer): int
    {
        $identifier = (string) $this->argument('site');

        $site = Site::query()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (! $site instanceof Site) {
            $this->error('No site found matching "'.$identifier.'".');

            return self::FAILURE;
        }

        if (! $site->usesFunctionsRuntime()) {
            $this->error('"'.$site->name.'" is not a serverless function site.');

            return self::FAILURE;
        }

        $yaml = $writer->render($site);
        $path = trim((string) $this->option('write'));

        if ($path === '') {
            $this->line($yaml);

            return self::SUCCESS;
        }

        if (file_put_contents($path, $yaml) === false) {
            $this->error('Could not write to '.$path.'.');

            return self::FAILURE;
        }

        $this->info('Wrote '.$path.'.');

        return self::SUCCESS;
    }
}
