<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "BusinessType",
  title: "BusinessType",
  description: "Tipo de negocio asociado a una tienda"
)]
class BusinessTypeSchema
{
  #[OA\Property(example: 1)]
  public int $id;

  #[OA\Property(example: "Ferretería")]
  public string $name;

  #[OA\Property(example: "Businesses that sell hardware and tools")]
  public string $description;

  #[OA\Property(example: "active")]
  public string $status;

  #[OA\Property(example: "2026-04-07T22:00:06.000000Z")]
  public string $created_at;

  #[OA\Property(example: "2026-04-07T22:00:06.000000Z")]
  public string $updated_at;
}
