<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
  schema: "ApiResponse",
  title: "Respuesta Estándar de la API",
  type: "object"
)]
class ApiResponse
{
  #[OA\Property(
    property: "status",
    type: "string",
    example: "success"
  )]
  public string $status;

  #[OA\Property(
    property: "message",
    type: "string",
    example: "Operación realizada correctamente"
  )]
  public string $message;

  #[OA\Property(
    property: "data",
    nullable: true
  )]
  public mixed $data;

  #[OA\Property(
    property: "errors",
    description: "Diccionario de errores de validación (campo => [mensajes])",
    type: "object",
    nullable: true,
    additionalProperties: new OA\AdditionalProperties(
      type: "array",
      items: new OA\Items(type: "string")
    ),
    example: [
      "email" => ["El formato del email es inválido"],
      "password" => ["La contraseña es obligatoria"]
    ]
  )]
  public mixed $errors;
}
