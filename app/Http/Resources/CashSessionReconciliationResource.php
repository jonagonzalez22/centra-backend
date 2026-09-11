<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashSessionReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->resource['session'];
        $summary = $this->resource['summary'];

        return [
            'id' => $session->id,
            'status' => $session->status,
            'business_date' => $session->business_date?->format('Y-m-d'),
            'opening_amount' => (float) $session->opening_amount,
            'expected_amount' => (float) $session->expected_amount,
            'declared_amount' => (float) $session->declared_amount,
            'opened_at' => $session->opened_at?->format('Y-m-d H:i:s'),
            'submitted_at' => $session->submitted_at?->format('Y-m-d H:i:s'),
            'declaration_notes' => $session->declaration_notes,
            'cashier' => [
                'id' => $session->user->id,
                'name' => $session->user->name,
            ],
            ...$summary,
        ];
    }
}
