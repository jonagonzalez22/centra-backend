<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'business_date' => $this->business_date?->format('Y-m-d'),
            'opening_amount' => (float) $this->opening_amount,
            'expected_amount' => (float) $this->expected_amount,
            'real_amount' => $this->real_amount !== null ? (float) $this->real_amount : null,
            'declared_amount' => $this->declared_amount !== null ? (float) $this->declared_amount : null,
            'opened_at' => $this->opened_at->format('Y-m-d H:i:s'),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i:s'),
            'notes' => $this->notes,
            'declaration_notes' => $this->declaration_notes,
            'reconciliation_notes' => $this->reconciliation_notes,
            'closed_by' => $this->closed_by === null
                ? null
                : $this->whenLoaded('closedBy', fn () => [
                    'id' => $this->closedBy->id,
                    'name' => $this->closedBy->name,
                ]),
            'difference' => $this->real_amount !== null
                ? ((int) round((float) $this->real_amount * 100) - (int) round((float) $this->expected_amount * 100)) / 100
                : null,
        ];
    }
}
