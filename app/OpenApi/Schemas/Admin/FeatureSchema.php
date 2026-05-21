<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "Feature",
  title: "Feature",
  description: "Funcionalidad del sistema"
)]
class FeatureSchema
{
  #[OA\Property(example: "019dd4bc-7318-7094-829b-a02485ba6caf")]
  public string $id;

  #[OA\Property(example: "pos")]
  public string $code;

  #[OA\Property(example: "Punto de venta")]
  public string $name;

  #[OA\Property(example: "Sistema de punto de venta integrado")]
  public string $description;

  #[OA\Property(example: "2026-04-27T22:00:06.000000Z")]
  public string $created_at;

  #[OA\Property(example: "2026-04-27T22:00:06.000000Z")]
  public string $updated_at;
}
