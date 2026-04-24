<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRequest;
use App\Models\Admin\Store;
use OpenApi\Attributes as OA;
use Illuminate\Http\Request;

class StoreController extends Controller
{
  #[OA\Get(
    path: "/admin/stores",
    summary: "Listar tiendas",
    description: "Retorna la lista paginada de tiendas con su tipo de negocio asociado. Permite filtrar por nombre, estado y tipo de negocio.",
    operationId: "storeIndex",
    security: [["sanctum" => []]],
    tags: ["Stores"]
  )]

  #[OA\Parameter(
    name: "name",
    in: "query",
    required: false,
    description: "Filtrar por nombre (búsqueda parcial)",
    schema: new OA\Schema(type: "string", example: "Ferretería")
  )]

  #[OA\Parameter(
    name: "is_active",
    in: "query",
    required: false,
    description: "Filtrar por estado",
    schema: new OA\Schema(type: "string", example: "active")
  )]

  #[OA\Parameter(
    name: "business_type_id",
    in: "query",
    required: false,
    description: "Filtrar por ID de tipo de negocio",
    schema: new OA\Schema(type: "integer", example: 1)
  )]

  #[OA\Parameter(
    name: "per_page",
    in: "query",
    required: false,
    description: "Cantidad de resultados por página (default: 15)",
    schema: new OA\Schema(type: "integer", example: 15)
  )]

  #[OA\Parameter(
    name: "page",
    in: "query",
    required: false,
    description: "Número de página",
    schema: new OA\Schema(type: "integer", example: 1)
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
              example: ["auth" => ["Token inválido o ausente"]]
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
              type: "object",
              properties: [
                new OA\Property(
                  property: "items",
                  type: "array",
                  items: new OA\Items(ref: "#/components/schemas/Store")
                ),
                new OA\Property(property: "total", type: "integer", example: 50),
                new OA\Property(property: "per_page", type: "integer", example: 15),
                new OA\Property(property: "current_page", type: "integer", example: 1),
                new OA\Property(property: "last_page", type: "integer", example: 4),
              ]
            ),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function index(Request $request)
  {
    $query = Store::with('businessType');

    $query->when(
      $request->filled('name'),
      fn($q) =>
      $q->where('name', 'like', '%' . $request->name . '%')
    );

    $query->when(
      $request->filled('is_active'),
      fn($q) =>
      $q->where('is_active', $request->boolean('is_active'))
    );

    $query->when(
      $request->filled('business_type_id'),
      fn($q) =>
      $q->where('business_type_id', $request->business_type_id)
    );

    $perPage = $request->integer('per_page', 15);
    $stores = $query->paginate($perPage);


    return response()->json([
      'status' => 'success',
      'message' => 'Listado de tiendas obtenido correctamente.',
      'data' => [
        'items'        => $stores->items(),
        'total'        => $stores->total(),
        'per_page'     => $stores->perPage(),
        'current_page' => $stores->currentPage(),
        'last_page'    => $stores->lastPage(),
      ],
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
        new OA\Property(property: "is_active", type: "boolean", example: true),
        new OA\Property(property: "inactive_reason", type: "string", nullable: true),
        new OA\Property(property: "inactive_at", type: "string", format: "date-time", nullable: true),
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
    $store = Store::create($request->validated());
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
    schema: new OA\Schema(type: "string")
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
        "is_active"
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
        new OA\Property(property: "is_active", type: "boolean", example: true),
        new OA\Property(property: "inactive_reason", type: "string", nullable: true),
        new OA\Property(property: "inactive_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "url_logo", type: "string", nullable: true, example: "https://www.testcentral.com/logo.png")
      ]
    )
  )]

  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    schema: new OA\Schema(type: "string")
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
    $store->update($request->validated());
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
    schema: new OA\Schema(type: "string")
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
    description: "Tienda eliminada correctamente.",
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
