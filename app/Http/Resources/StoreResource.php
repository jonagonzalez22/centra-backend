<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id'             => $this->id,
      'name'           => $this->name,
      'cuit'           => $this->cuit,
      'address'        => $this->address,
      'state'          => $this->state,
      'city'           => $this->city,
      'country'        => $this->country,
      'phone'          => $this->phone,
      'email'          => $this->email,
      'is_active'      => $this->is_active,
      'inactive_reason' => $this->inactive_reason,
      'inactive_at'    => $this->inactive_at?->format('Y-m-d H:i:s'),
      'url_logo'       => $this->url_logo,
      'trial_ends_at'   => $this->trial_ends_at?->format('Y-m-d'),
      'created_at'      => $this->created_at?->format('Y-m-d H:i:s'),
      'updated_at'      => $this->updated_at?->format('Y-m-d H:i:s'),
      'business_type'  => BusinessTypesResource::make($this->whenLoaded('businessType')),
      'plan' => PlanResource::make($this->whenLoaded('plan')),
    ];
  }
}
