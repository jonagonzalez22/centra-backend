<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Role",
    title: "Rol",
    description: "Rol del sistema"
)]
class RoleSchema
{
    #[OA\Property(example: 1)]
    public int $id;

    #[OA\Property(example: "STORE_ADMIN")]
    public string $name;

    #[OA\Property(example: "web")]
    public string $guard_name;

    #[OA\Property(type: "integer", example: 5)]
    public int $users_count;

    #[OA\Property(type: "integer", example: 8)]
    public int $permissions_count;

    #[OA\Property(type: "array", items: new OA\Items(type: "string"), example: ["stores.view", "stores.create"])]
    public array $permissions;

    #[OA\Property(example: "2026-04-27T22:00:06.000000Z")]
    public string $created_at;

    #[OA\Property(example: "2026-04-27T22:00:06.000000Z")]
    public string $updated_at;
}
