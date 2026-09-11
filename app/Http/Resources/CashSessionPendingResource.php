<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashSessionPendingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'business_date' => $this->business_date?->format('Y-m-d'),
            'opened_at' => $this->opened_at?->format('Y-m-d H:i:s'),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'declared_amount' => (float) $this->declared_amount,
            'declaration_notes' => $this->declaration_notes,
            'cashier' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
