<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "Plan",
  title: "Plan",
  description: "Plan de suscripción"
)]
class PlanSchema
{
  #[OA\Property(example: "019dd4bc-7318-7094-829b-a02485ba6caf")]
  public string $id;

  #[OA\Property(example: "Plan Básico")]
  public string $name;

  #[OA\Property(example: "Plan ideal para pequeños negocios")]
  public string $description;

  #[OA\Property(type: "number", format: "float", example: 29.99)]
  public float $price;

  #[OA\Property(enum: ["monthly", "yearly"], example: "monthly")]
  public string $billing_cycle;

  #[OA\Property(type: "boolean", example: true)]
  public bool $is_active;

  #[OA\Property(type: "boolean", example: false)]
  public bool $is_trial;

  #[OA\Property(type: "array", items: new OA\Items(ref: "#/components/schemas/Feature"))]
  public array $features;

  #[OA\Property(example: "2026-04-27T22:00:06.000000Z")]
  public string $created_at;

  #[OA\Property(example: "2026-04-27T22:00:06.000000Z")]
  public string $updated_at;
}
