<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "StoreLight",
  title: "StoreLight",
  description: "Versión simplificada de Store"
)]
class StoreLightSchema
{
  #[OA\Property(example: "30dd3bb-7320-7094-829b-a02485ba6cbb")]
  public string $id;

  #[OA\Property(example: "Ferretería El Tornillo Feliz")]
  public string $name;

  #[OA\Property(example: "Ferretería")]
  public string $business_type;
}
