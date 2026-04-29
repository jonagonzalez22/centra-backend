<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "PlanLight",
  title: "PlanLight",
  description: "Versión simplificada de plans para Store"
)]
class PlanLightSchema
{
  #[OA\Property(example: "019dd4bc-7318-7094-829b-a02485ba6caf")]
  public string $id;

  #[OA\Property(example: "Beta")]
  public string $name;
}
