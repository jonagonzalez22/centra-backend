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
  #[OA\Property(property: "id", type: "string", example: "123e4567-e89b-12d3-a456-426614174000")]
  public int $id;

  #[OA\Property(property: "name", type: "string", example: "Admin Centra")]
  public string $name;

  #[OA\Property(property: "email", type: "string", format: "email", example: "admin@centra.com")]
  public string $email;

  #[OA\Property(property: "store_id", type: "string", example: "9482050g-0b29-4281-b9a7-7f19a14b3e6d", nullable: true)]
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

  #[OA\Property(
    property: "features",
    type: "array",
    items: new OA\Items(type: "string"),
    example: ["feature1", "feature2"]
  )]
  public array $features;
}
