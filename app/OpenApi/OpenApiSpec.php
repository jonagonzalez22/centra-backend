<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
  title: "CENTRA API",
  version: "1.0.0",
  description: "Documentación técnica de la API para el proyecto CENTRA. Auth gestionada con Laravel Sanctum.",
  contact: new OA\Contact(email: "soporte@centra.com")
)]

#[OA\Server(
  url: "http://localhost/api/v1",
  description: "Servidor Local"
)]

#[OA\SecurityScheme(
  securityScheme: "sanctum",
  type: "apiKey",
  name: "Authorization",
  in: "header",
  description: "Ingrese el token en formato: Bearer {token}"
)]
class OpenApiSpec
{
  // Clase vacía para contener anotaciones globales
}
