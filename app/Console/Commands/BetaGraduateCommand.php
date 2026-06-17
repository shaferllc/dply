<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Server;
use App\Support\Beta\BetaProgram;
use Illuminate\Console\Command;

/**
 * Graduate beta orgs at the global cutover (Q6: global end + warn-then-convert).
 * Run at/after `subscription.standard.beta.cutover_at`. Idempotent.
 *
 * For each org that joined the beta and isn't already subscribed:
 *  - Reseed a fresh normal trial (a fair grace window to add a card), so they
 *    don't land straight on the expired-trial pause ladder.
 *  - Finalize the free CX22 comp: stamp comped_until = cutover so the box starts
 *    billing after the grace (BYO servers are untouched — they're on the user's
 *    own cloud). Operators warn-then-destroy unpaid boxes via dply:managed:kill.
 *
 *   php artisan dply:beta:graduate [--dry-run]
 */
class BetaGraduateCommand extends Command
{
    protected $signature = 'dply:beta:graduate {--dry-run : Report what would change without writing}';

    protected $description = 'Graduate beta orgs to the normal trial at the global cutover.';

    public function handle(): int
    {
        $cutover = BetaProgram::cutoverAt();

        if ($cutover === null) {
            $this->warn('No beta cutover date set (subscription.standard.beta.cutover_at) — nothing to graduate.');

            return self::SUCCESS;
        }

        if ($cutover->isFuture()) {
            $this->warn("Beta cutover is {$cutover->toDateString()} (future). Run on/after that date.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $trialDays = (int) config('subscription.standard.trial_days', 14);
        $orgs = Organization::query()->whereNotNull('beta_joined_at')->get();

        $graduated = 0;
        $comped = 0;

        foreach ($orgs as $org) {
            // Subscribed-early orgs already pay normally; leave their trial alone
            // but still finalize the comp window on their free box below.
            if (! $org->onAnyPaidPlan()) {
                $this->line("Org {$org->name} ({$org->id}) → fresh {$trialDays}-day trial");

                if (! $dryRun) {
                    $org->forceFill(['trial_ends_at' => now()->addDays($trialDays)])->save();
                }

                $graduated++;
            }

            // Stamp the cutover onto any still-open comp so the free box converts
            // to billed after the grace window.
            $boxes = $org->servers()
                ->where('hosting_backend', Server::HOSTING_BACKEND_DPLY)
                ->get()
                ->filter(fn (Server $s) => $s->isManagedVm() && ($s->getRawOriginal('comped_until') === null || $s->comped_until->isFuture()));

            foreach ($boxes as $box) {
                $this->line("  comp finalize: server {$box->id} comped_until={$cutover->toDateString()}");

                if (! $dryRun) {
                    $box->forceFill(['comped_until' => $cutover])->save();
                }

                $comped++;
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Graduated {$graduated} org(s); finalized comp on {$comped} managed box(es).");

        return self::SUCCESS;
    }
}
