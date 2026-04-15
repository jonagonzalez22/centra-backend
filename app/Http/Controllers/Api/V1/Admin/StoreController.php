<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\admin\StoreRequest;
use App\Models\Admin\Store;
use OpenApi\Attributes as OA;

class StoreController extends Controller
{
  #[OA\Get(
    path: "/admin/stores",
    summary: "Listar tiendas",
    description: "Retorna la lista completa de tiendas con su tipo de negocio asociado.",
    operationId: "storeIndex",
    security: [["sanctum" => []]],
    tags: ["Stores"]
  )]

  #[OA\Response(
    response: 401,
    description: "No autenticado",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No autenticado."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(
              property: "errors",
              type: "object",
              example: [
                "auth" => ["Token inválido o ausente"]
              ]
            )
          ]
        )
      ]
    )
  )]

  #[OA\Response(
    response: 200,
    description: "Listado de tiendas obtenido correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(
              property: "data",
              type: "array",
              items: new OA\Items(ref: "#/components/schemas/Store")
            ),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function index()
  {
    return response()->json([
      'status' => 'success',
      'message' => 'Listado de tiendas obtenido correctamente.',
      'data' => Store::with('businessType')->get(),
      'errors' => null,
    ]);
  }

  #[OA\Post(
    path: "/admin/stores",
    summary: "Crear tienda",
    description: "Crea una nueva tienda en el sistema.",
    operationId: "storeCreate",
    security: [["sanctum" => []]],
    tags: ["Stores"]
  )]

  #[OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
      required: [
        "name",
        "business_type_id",
        "cuit",
        "address",
        "state",
        "city",
        "country",
        "phone",
        "email",
        "status"
      ],
      properties: [
        new OA\Property(property: "name", type: "string", example: "Ferretería test"),
        new OA\Property(property: "business_type_id", type: "integer", example: 1),
        new OA\Property(property: "cuit", type: "string", example: "20345678595"),
        new OA\Property(property: "address", type: "string", example: "Tunuyan 782"),
        new OA\Property(property: "state", type: "string", example: "Ciudad"),
        new OA\Property(property: "city", type: "string", example: "Mendoza"),
        new OA\Property(property: "country", type: "string", example: "Argentina"),
        new OA\Property(property: "phone", type: "string", example: "+5426112345678"),
        new OA\Property(property: "email", type: "string", example: "ferreTest@central.com"),
        new OA\Property(property: "status", type: "string", example: "active"),
        new OA\Property(property: "url_logo", type: "string", nullable: true, example: "https://www.testcentral.com/logo.png")
      ]
    )
  )]

  #[OA\Response(
    response: 401,
    description: "No autenticado",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No autenticado."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", type: "object")
          ]
        )
      ]
    )
  )]

  #[OA\Response(
    response: 201,
    description: "Tienda creada correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(
              property: "data",
              ref: "#/components/schemas/Store"
            ),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function store(StoreRequest $request)
  {
    $store = Store::create($request->all());
    $store->load('businessType');

    return response()->json([
      'status' => 'success',
      'message' => 'Tienda creada correctamente.',
      'data' => $store,
      'errors' => null,
    ], 201);
  }

  #[OA\Get(
    path: "/admin/stores/{id}",
    summary: "Obtener tienda por ID",
    description: "Retorna una tienda específica con su tipo de negocio.",
    operationId: "storeShow",
    security: [["sanctum" => []]],
    tags: ["Stores"]
  )]

  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    description: "ID de la tienda",
    schema: new OA\Schema(type: "integer")
  )]

  #[OA\Response(
    response: 401,
    description: "No autenticado",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]

  #[OA\Response(
    response: 404,
    description: "Tienda no encontrada",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "Tienda no encontrada."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(
              property: "errors",
              type: "object",
              example: [
                "store" => ["No existe una tienda con ese ID"]
              ]
            )
          ]
        )
      ]
    )
  )]

  #[OA\Response(
    response: 200,
    description: "Tienda obtenida correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(
              property: "data",
              ref: "#/components/schemas/Store"
            ),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function show(string $id)
  {
    $store = Store::with('businessType')->findOrFail($id);

    return response()->json([
      'status' => 'success',
      'message' => 'Tienda obtenida correctamente.',
      'data' => $store,
      'errors' => null,
    ]);
  }

  #[OA\Put(
    path: "/admin/stores/{id}",
    summary: "Actualizar tienda",
    description: "Actualiza una tienda existente.",
    operationId: "storeUpdate",
    security: [["sanctum" => []]],
    tags: ["Stores"]
  )]

  #[OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
      required: [
        "name",
        "business_type_id",
        "cuit",
        "address",
        "state",
        "city",
        "country",
        "phone",
        "email",
        "status"
      ],
      properties: [
        new OA\Property(property: "name", type: "string", example: "Ferretería test"),
        new OA\Property(property: "business_type_id", type: "integer", example: 1),
        new OA\Property(property: "cuit", type: "string", example: "20345678595"),
        new OA\Property(property: "address", type: "string", example: "Tunuyan 782"),
        new OA\Property(property: "state", type: "string", example: "Ciudad"),
        new OA\Property(property: "city", type: "string", example: "Mendoza"),
        new OA\Property(property: "country", type: "string", example: "Argentina"),
        new OA\Property(property: "phone", type: "string", example: "+5426112345678"),
        new OA\Property(property: "email", type: "string", example: "ferreTest@central.com"),
        new OA\Property(property: "status", type: "string", example: "active"),
        new OA\Property(property: "url_logo", type: "string", nullable: true, example: "https://www.testcentral.com/logo.png")
      ]
    )
  )]

  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    schema: new OA\Schema(type: "integer")
  )]

  #[OA\Response(
    response: 401,
    description: "No autenticado",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]

  #[OA\Response(
    response: 404,
    description: "Tienda no encontrada",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]

  #[OA\Response(
    response: 200,
    description: "Tienda actualizada correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(
              property: "data",
              ref: "#/components/schemas/Store"
            ),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function update(StoreRequest $request, string $id)
  {
    $store = Store::findOrFail($id);
    $store->update($request->all());
    $store->load('businessType');

    return response()->json([
      'status' => 'success',
      'message' => 'Tienda actualizada correctamente.',
      'data' => $store,
      'errors' => null,
    ]);
  }

  #[OA\Delete(
    path: "/admin/stores/{id}",
    summary: "Eliminar tienda",
    description: "Elimina una tienda por ID.",
    operationId: "storeDelete",
    security: [["sanctum" => []]],
    tags: ["Stores"]
  )]

  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    schema: new OA\Schema(type: "integer")
  )]

  #[OA\Response(
    response: 401,
    description: "No autenticado",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]

  #[OA\Response(
    response: 404,
    description: "Tienda no encontrada",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]

  #[OA\Response(
    response: 200,
    description: "Tienda eliminada correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function destroy(string $id)
  {
    $store = Store::findOrFail($id);
    $store->delete();

    return response()->json([
      'status' => 'success',
      'message' => 'Tienda eliminada correctamente.',
      'data' => null,
      'errors' => null,
    ]);
  }
}
