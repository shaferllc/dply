<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Models\Site;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-scoped index of serverless functions — the landing page that lists
 * every function and links into each one's workspace and deploy journey.
 *
 * Functions are Site rows distinguished by their runtime profile (a DO
 * Functions or AWS Lambda web action), so they don't surface cleanly in the
 * VM-oriented /sites list; this gives them a home of their own.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        abort_unless(Feature::active('surface.serverless'), 404);
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $functions = Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('meta->runtime_profile', ['digitalocean_functions_web', 'aws_lambda_bref_web'])
            ->with('server:id,name')
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.serverless.index', [
            'functions' => $functions,
        ]);
    }
}
