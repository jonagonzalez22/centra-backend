<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommercialOperationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'previous_date' => $this->previous_date?->format('Y-m-d'),
            'new_date' => $this->new_date?->format('Y-m-d'),
            'previous_status' => $this->previous_status,
            'new_status' => $this->new_status,
            'reason' => $this->reason,
            'reason_code' => $this->reason_code,
            'reason_note' => $this->reason_note,
            'observation' => $this->observation,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
