<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id'          => $this->id,
      'name'        => $this->name,
      'email'       => $this->email,
      'store_id'    => $this->store_id,
      'roles'       => $this->getRoleNames()->toArray(),
      'permissions' => $this->getPermissionsViaRoles()->pluck('name')->toArray(),
      'features'    => $this->whenLoaded('store', function () {
        return $this->store?->plan?->features->pluck('code')->toArray() ?? [];
      }, []),
    ];
  }
}
