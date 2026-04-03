<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "User",
  title: "Usuario",
  description: "Modelo de usuario de CENTRA",
  type: "object"
)]
class UserSchema
{
  #[OA\Property(property: "id", type: "integer", example: 1)]
  public int $id;

  #[OA\Property(property: "name", type: "string", example: "Admin Centra")]
  public string $name;

  #[OA\Property(property: "email", type: "string", format: "email", example: "admin@centra.com")]
  public string $email;

  #[OA\Property(property: "store_id", type: "number", example: "39", nullable: true)]
  public string $store_id;

  #[OA\Property(
    property: "roles",
    type: "array",
    items: new OA\Items(type: "string"),
    example: ["admin"]
  )]
  public array $roles;

  #[OA\Property(
    property: "permissions",
    type: "array",
    items: new OA\Items(type: "string"),
    example: ["cash.view"]
  )]
  public array $permissions;
}
