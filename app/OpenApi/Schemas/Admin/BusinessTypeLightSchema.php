<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "BusinessTypeLight",
  title: "BusinessTypeLight",
  description: "Versión simplificada de BusinessType para Store"
)]
class BusinessTypeLightSchema
{
  #[OA\Property(example: "30dd3bb-7320-7094-829b-a02485ba6cad")]
  public string $id;

  #[OA\Property(example: "Ferretería")]
  public string $name;
}
