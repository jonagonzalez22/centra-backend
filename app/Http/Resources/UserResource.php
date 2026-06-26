<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'store' => new SimpleStoreResource($this->whenLoaded('store')),
            'roles' => $this->getRoleNames()->toArray(),
            'permissions' => $this->getPermissionsViaRoles()->pluck('name')->toArray(),
            'features' => $this->whenLoaded('store', function () {
                return $this->store?->plan?->features->pluck('code')->toArray() ?? [];
            }, []),
        ];
    }
}
