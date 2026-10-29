<?php

namespace App\Observers;

use App\Enums\IncidentStatus;
use App\Models\SafetyIncident;
use App\Services\StrikeService;

/**
 * Keeps direct SafetyIncident writes in sync with the strike ledger.
 * Student reports are created as PENDING, so this observer does nothing for them.
 * Only a confirmed incident reaches StrikeService. The service is idempotent.
 */
class SafetyIncidentObserver
{
    public function created(SafetyIncident $incident): void
    {
        if ($incident->status === IncidentStatus::Confirmed) {
            app(StrikeService::class)->applyForIncident($incident);
        }
    }

    public function updated(SafetyIncident $incident): void
    {
        if ($incident->status === IncidentStatus::Confirmed && $incident->strike_applied_at === null) {
            app(StrikeService::class)->applyForIncident($incident);
        }
    }

    public function deleted(SafetyIncident $incident): void
    {
        //
    }
}
