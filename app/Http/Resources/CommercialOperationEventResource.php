<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="CommercialOperationEventResource",
 *   type="object",
 *   title="CommercialOperationEventResource",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="event_type", type="string"),
 *   @OA\Property(property="previous_status", type="string", nullable=true),
 *   @OA\Property(property="new_status", type="string", nullable=true),
 *   @OA\Property(property="reason", type="string"),
 *   @OA\Property(property="reason_code", type="string", nullable=true),
 *   @OA\Property(property="reason_note", type="string", nullable=true),
 *   @OA\Property(property="observation", type="string", nullable=true),
 *   @OA\Property(property="old_values", type="object", nullable=true,
 *     @OA\Property(property="status", type="string", nullable=true),
 *     @OA\Property(property="date", type="string", format="date", nullable=true)
 *   ),
 *   @OA\Property(property="new_values", type="object", nullable=true,
 *     @OA\Property(property="status", type="string", nullable=true),
 *     @OA\Property(property="date", type="string", format="date", nullable=true)
 *   ),
 *   @OA\Property(property="user", type="object", nullable=true,
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string")
 *   ),
 *   @OA\Property(property="created_at", type="string", format="date-time")
 * )
 */
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
            'old_values' => [
                'status' => $this->previous_status,
                'date' => $this->previous_date?->format('Y-m-d'),
            ],
            'new_values' => [
                'status' => $this->new_status,
                'date' => $this->new_date?->format('Y-m-d'),
            ],
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
