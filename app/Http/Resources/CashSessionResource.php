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
            'opening_amount' => (float) $this->opening_amount,
            'expected_amount' => (float) $this->expected_amount,
            'real_amount' => $this->real_amount !== null ? (float) $this->real_amount : null,
            'opened_at' => $this->opened_at->format('Y-m-d H:i:s'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i:s'),
            'notes' => $this->notes,
        ];
    }
}
