<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "Store",
  title: "Store",
  description: "Entidad tienda con su tipo de negocio"
)]
class StoreSchema
{
  #[OA\Property(example: 3)]
  public int $id;

  #[OA\Property(example: "Ferretería Central")]
  public string $name;

  #[OA\Property(example: 1)]
  public int $business_type_id;

  #[OA\Property(example: "20-1234567890")]
  public string $cuit;

  #[OA\Property(example: "Sarmiento 4455")]
  public string $address;

  #[OA\Property(example: "Buenos Aires")]
  public string $state;

  #[OA\Property(example: "Buenos Aires")]
  public string $city;

  #[OA\Property(example: "Argentina")]
  public string $country;

  #[OA\Property(example: "+54 11 1234-5678")]
  public string $phone;

  #[OA\Property(example: "ferreteria@central.com")]
  public string $email;

  #[OA\Property(example: true)]
  public bool $is_active;

  #[OA\Property(example: null)]
  public ?string $inactive_reason;

  #[OA\Property(example: null)]
  public ?string $inactive_at;

  #[OA\Property(example: "https://www.testcentral.com/logo.png")]
  public string $url_logo;

  #[OA\Property(example: "2026-04-07T22:30:49.000000Z")]
  public string $created_at;

  #[OA\Property(example: "2026-04-07T22:30:49.000000Z")]
  public string $updated_at;

  #[OA\Property(
    property: "business_type",
    ref: "#/components/schemas/BusinessType"
  )]
  public $business_type;
}
