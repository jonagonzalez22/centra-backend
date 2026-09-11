<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashSessionBlindResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'business_date' => $this->business_date?->format('Y-m-d'),
            'opening_amount' => (float) $this->opening_amount,
            'declared_amount' => $this->declared_amount !== null ? (float) $this->declared_amount : null,
            'opened_at' => $this->opened_at?->format('Y-m-d H:i:s'),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'notes' => $this->notes,
            'declaration_notes' => $this->declaration_notes,
        ];
    }
}
