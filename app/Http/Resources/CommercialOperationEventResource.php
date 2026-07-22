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
            'reason' => $this->reason,
            'observation' => $this->observation,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
