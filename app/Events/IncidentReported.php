<?php

namespace App\Events;

use App\Models\SafetyIncident;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentReported implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SafetyIncident $incident;

    public function __construct(SafetyIncident $incident)
    {
        $this->incident = $incident;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'incident.reported';
    }

    public function broadcastWith(): array
    {
        $category = $this->incident->categoryEnum();

        return [
            'incident' => [
                'id' => $this->incident->id,
                'type' => $this->incident->type?->value,
                'type_label' => $this->incident->type?->label(),
                'status' => $this->incident->status?->value,
                'category' => $this->incident->category,
                'category_label' => $category?->label() ?? 'Unclassified concern',
                'severity' => $this->incident->severity?->value,
                'severity_label' => $this->incident->severity?->label() ?? 'Pending classification',
                'strike_weight' => $this->incident->strike_weight,
                'description' => $this->incident->description,
                'location' => $this->incident->location,
                'created_at' => $this->incident->created_at?->toIso8601String(),
            ],
            'instructor' => $this->incident->instructor ? [
                'id' => $this->incident->instructor->id,
                'name' => $this->incident->instructor->name,
            ] : null,
            'booking' => $this->incident->booking ? [
                'id' => $this->incident->booking->id,
                'date' => $this->incident->booking->date->format('Y-m-d'),
            ] : null,
            'reported_by' => $this->incident->reporter ? [
                'id' => $this->incident->reporter->id,
                'name' => $this->incident->reporter->name,
            ] : null,
            'message' => 'New ' . ($category?->label() ?? 'session concern') . ' reported for SISA review.',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
