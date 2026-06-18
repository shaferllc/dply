<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\RunSiteFixerJob;
use App\Jobs\ScanSiteEnvRequirementsJob;
use App\Jobs\SyncEnvFromServerJob;
use App\Jobs\SyncWorkerPoolEnvJob;
use App\Jobs\TestSiteHealthJob;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Show;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecretResidency;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SecretEscalator;
use App\Services\Sites\SiteEnvPushScheduler;
use App\Support\Sites\EnvImportSources;
use App\Support\Sites\SiteFixers;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The site .env editor: viewing/editing the encrypted env cache, syncing it
 * from / pushing it to the server's live .env, the detected-requirement
 * "missing variables" prompt, and the file-path relocation controls.
 *
 * Lifted out of {@see Show} so the same editor can be
 * embedded as the Deploy hub's Environment tab. The host component must also
 * use {@see ConfirmsActionWithModal}, {@see DispatchesToastNotifications} and
 * {@see WatchesConsoleActionOutcomes}, and expose
 * `$this->site` and `$this->server`.
 *
 * @phpstan-require-extends Component
 *
 * @property Server $server
 * @property Site $site
 */
trait ManagesSiteEnvironment
{
    use ManagesSiteEnvCrud;
    use ManagesSiteEnvImportFix;
    use ManagesSiteEnvRequirements;
    use ManagesSiteEnvSync;

    public string $new_env_key = '';

    public string $new_env_value = '';

    public string $new_env_comment = '';

    public string $bulk_env_input = '';

    /**
     * Keys ticked for bulk removal. Bound to the per-row checkboxes; cleared
     * after a bulk remove. Bulk delete writes the cache once and pushes once.
     *
     * @var list<string>
     */
    public array $selected_env_keys = [];

    public ?string $env_import_key = null;

    public ?string $editing_env_key = null;

    public string $editing_env_value = '';

    public string $editing_env_comment = '';

    /** Key currently open in the single-variable "Fix" modal ('' = closed). */
    public ?string $fixing_env_key = null;

    public string $fixing_env_value = '';

    /** @var list<string> */
    public array $revealed_env_keys = [];

    /** Decrypted plaintext of escrowed (residency) keys the operator revealed, keyed by env key. */
    public array $revealed_escrow_values = [];

    public string $env_file_path_override = '';

    /** Live filter for the variables list (matches key names, case-insensitive). */
    public string $env_search = '';

    /** Selected prefix group to filter the variables list ('' = all). */
    public string $env_group = '';

    /** 1-based page for the (in-memory) variables list. */
    public int $env_page = 1;


    /** Editable buffer for the "Edit all" modal (the full .env as text). */
    public string $edit_all_env = '';

    /** @var array<string, string> */
    public array $missing_env_values = [];


}
