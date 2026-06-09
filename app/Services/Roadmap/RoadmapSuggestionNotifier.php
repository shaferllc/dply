<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use App\Mail\RoadmapSuggestionStatusMail;
use App\Models\RoadmapItem;
use App\Models\RoadmapSuggestion;
use Illuminate\Support\Facades\Mail;

class RoadmapSuggestionNotifier
{
    public function notifyReviewed(RoadmapSuggestion $suggestion): void
    {
        $this->send($suggestion, 'reviewed');
    }

    public function notifyDeclined(RoadmapSuggestion $suggestion): void
    {
        $this->send($suggestion, 'declined');
    }

    public function notifyPromoted(RoadmapSuggestion $suggestion, RoadmapItem $item): void
    {
        $this->send($suggestion, 'promoted', $item);
    }

    private function send(RoadmapSuggestion $suggestion, string $event, ?RoadmapItem $item = null): void
    {
        if (! filter_var(config('roadmap.suggestion_emails_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (! filled($suggestion->email)) {
            return;
        }

        Mail::to($suggestion->email)->send(new RoadmapSuggestionStatusMail($suggestion, $event, $item));
    }
}
