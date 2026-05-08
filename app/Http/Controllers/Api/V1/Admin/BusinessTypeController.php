<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CreateBusinessTypeRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBusinessTypeRequest;
use App\Http\Resources\BusinessTypeResource;
use App\Models\BusinessType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class BusinessTypeController extends Controller
{
  #[OA\Get(
    path: "/admin/business-types",
    summary: "Listar tipos de negocio",
    description: "Retorna la lista paginada de tipos de negocio. Permite filtrar por nombre y estado.",
    operationId: "businessTypeIndex",
    security: [["sanctum" => []]],
    tags: ["Business Types"]
  )]
  #[OA\Parameter(name: "name", in: "query", required: false, description: "Filtrar por nombre (búsqueda parcial)", schema: new OA\Schema(type: "string", example: "Ferretería"))]
  #[OA\Parameter(name: "status", in: "query", required: false, description: "Filtrar por estado", schema: new OA\Schema(type: "string", enum: ["active", "inactive"], example: "active"))]
  #[OA\Parameter(name: "per_page", in: "query", required: false, description: "Resultados por página (default: 15)", schema: new OA\Schema(type: "integer", example: 15))]
  #[OA\Parameter(name: "page", in: "query", required: false, description: "Número de página", schema: new OA\Schema(type: "integer", example: 1))]
  #[OA\Response(
    response: 200,
    description: "Listado de tipos de negocio obtenido correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "success"),
            new OA\Property(property: "message", example: "Listado de tipos de negocio obtenido correctamente."),
            new OA\Property(
              property: "data",
              type: "object",
              properties: [
                new OA\Property(property: "items", type: "array", items: new OA\Items(ref: "#/components/schemas/BusinessType")),
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
            new OA\Property(property: "errors", type: "object", example: ["auth" => ["Token inválido o ausente"]])
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 403,
    description: "Sin permisos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No tenés permisos para realizar esta acción."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function index(Request $request): JsonResponse
  {
    $query = BusinessType::query();

    $query->when($request->filled('name'), function ($q) use ($request) {
      $q->where('name', 'like', '%' . $request->name . '%');
    });

    $query->when($request->filled('status'), function ($q) use ($request) {
      $q->where('status', $request->status);
    });

    $perPage = $request->integer('per_page', 15);
    $businessTypes = $query->paginate($perPage);

    return response()->json([
      'status'  => 'success',
      'message' => 'Listado de tipos de negocio obtenido correctamente.',
      'data'    => [
        'items'        => BusinessTypeResource::collection($businessTypes->items()),
        'total'        => $businessTypes->total(),
        'per_page'     => $businessTypes->perPage(),
        'current_page' => $businessTypes->currentPage(),
        'last_page'    => $businessTypes->lastPage(),
      ],
      'errors'  => null,
    ]);
  }

  #[OA\Post(
    path: "/admin/business-types",
    summary: "Crear tipo de negocio",
    description: "Crea un nuevo tipo de negocio en el sistema.",
    operationId: "businessTypeStore",
    security: [["sanctum" => []]],
    tags: ["Business Types"]
  )]
  #[OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
      required: ["name", "status"],
      properties: [
        new OA\Property(property: "name", type: "string", example: "Ferretería"),
        new OA\Property(property: "description", type: "string", nullable: true, example: "Businesses that sell hardware and tools"),
        new OA\Property(property: "status", type: "string", enum: ["active", "inactive"], example: "active"),
      ]
    )
  )]
  #[OA\Response(
    response: 201,
    description: "Tipo de negocio creado correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "data", ref: "#/components/schemas/BusinessType"),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
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
    response: 403,
    description: "Sin permisos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No tenés permisos para realizar esta acción."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 422,
    description: "Error de validación",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "Error de validación."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", type: "object")
          ]
        )
      ]
    )
  )]
  public function store(CreateBusinessTypeRequest $request): JsonResponse
  {
    $businessType = BusinessType::create($request->validated());

    return response()->json([
      'status'  => 'success',
      'message' => 'Tipo de negocio creado correctamente.',
      'data'    => BusinessTypeResource::make($businessType),
      'errors'  => null,
    ], 201);
  }

  #[OA\Get(
    path: "/admin/business-types/{id}",
    summary: "Obtener tipo de negocio por ID",
    description: "Retorna un tipo de negocio específico.",
    operationId: "businessTypeShow",
    security: [["sanctum" => []]],
    tags: ["Business Types"]
  )]
  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    description: "ID del tipo de negocio",
    schema: new OA\Schema(type: "integer", example: 1)
  )]
  #[OA\Response(
    response: 200,
    description: "Tipo de negocio obtenido correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "success"),
            new OA\Property(property: "message", example: "Tipo de negocio obtenido correctamente."),
            new OA\Property(property: "data", ref: "#/components/schemas/BusinessType"),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
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
            new OA\Property(property: "errors", type: "object", example: ["auth" => ["Token inválido o ausente"]])
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 403,
    description: "Sin permisos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No tenés permisos para realizar esta acción."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 404,
    description: "Tipo de negocio no encontrado",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "Tipo de negocio no encontrado."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(
              property: "errors",
              type: "object",
              example: ["business_type" => ["No existe un tipo de negocio con ese ID"]]
            ),
          ]
        )
      ]
    )
  )]
  public function show(string $id): JsonResponse
  {
    $businessType = BusinessType::find($id);

    if (!$businessType) {
      return response()->json([
        'status'  => 'error',
        'message' => 'Tipo de negocio no encontrado.',
        'data'    => null,
        'errors'  => ['business_type' => ['No existe un tipo de negocio con ese ID']],
      ], 404);
    }

    return response()->json([
      'status'  => 'success',
      'message' => 'Tipo de negocio obtenido correctamente.',
      'data'    => BusinessTypeResource::make($businessType),
      'errors'  => null,
    ]);
  }

  #[OA\Put(
    path: "/admin/business-types/{id}",
    summary: "Actualizar tipo de negocio",
    description: "Actualiza un tipo de negocio existente.",
    operationId: "businessTypeUpdate",
    security: [["sanctum" => []]],
    tags: ["Business Types"]
  )]
  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    description: "ID del tipo de negocio",
    schema: new OA\Schema(type: "integer", example: 1)
  )]
  #[OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
      properties: [
        new OA\Property(property: "name", type: "string", example: "Ferretería actualizada"),
        new OA\Property(property: "description", type: "string", nullable: true, example: "Descripción actualizada"),
        new OA\Property(property: "status", type: "string", enum: ["active", "inactive"], example: "active"),
      ]
    )
  )]
  #[OA\Response(
    response: 200,
    description: "Tipo de negocio actualizado correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "success"),
            new OA\Property(property: "message", example: "Tipo de negocio actualizado correctamente."),
            new OA\Property(property: "data", ref: "#/components/schemas/BusinessType"),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
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
            new OA\Property(property: "errors", type: "object", example: ["auth" => ["Token inválido o ausente"]])
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 403,
    description: "Sin permisos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No tenés permisos para realizar esta acción."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 404,
    description: "Tipo de negocio no encontrado",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "Tipo de negocio no encontrado."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 422,
    description: "Error de validación",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "Error de validación."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", type: "object")
          ]
        )
      ]
    )
  )]
  public function update(UpdateBusinessTypeRequest $request, string $id): JsonResponse
  {
    $businessType = BusinessType::find($id);

    if (!$businessType) {
      return response()->json([
        'status'  => 'error',
        'message' => 'Tipo de negocio no encontrado.',
        'data'    => null,
        'errors'  => null,
      ], 404);
    }

    $businessType->update($request->validated());

    return response()->json([
      'status'  => 'success',
      'message' => 'Tipo de negocio actualizado correctamente.',
      'data'    => BusinessTypeResource::make($businessType),
      'errors'  => null,
    ]);
  }

  #[OA\Delete(
    path: "/admin/business-types/{id}",
    summary: "Eliminar tipo de negocio",
    description: "Elimina un tipo de negocio por ID. No se puede eliminar si tiene tiendas asociadas.",
    operationId: "businessTypeDestroy",
    security: [["sanctum" => []]],
    tags: ["Business Types"]
  )]
  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    description: "ID del tipo de negocio a eliminar",
    schema: new OA\Schema(type: "integer", example: 1)
  )]
  #[OA\Response(
    response: 200,
    description: "Tipo de negocio eliminado correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "success"),
            new OA\Property(property: "message", example: "Tipo de negocio eliminado correctamente."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 401,
    description: "No autenticado",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]
  #[OA\Response(
    response: 403,
    description: "Sin permisos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No tenés permisos para realizar esta acción."),
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 404,
    description: "Tipo de negocio no encontrado",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]
  #[OA\Response(
    response: 409,
    description: "Conflicto - tiene tiendas asociadas",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No se puede eliminar el tipo de negocio porque tiene tiendas asociadas."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", type: "object"),
          ]
        )
      ]
    )
  )]
  public function destroy(string $id): JsonResponse
  {
    $businessType = BusinessType::find($id);

    if (!$businessType) {
      return response()->json([
        'status'  => 'error',
        'message' => 'Tipo de negocio no encontrado.',
        'data'    => null,
        'errors'  => null,
      ], 404);
    }

    if ($businessType->stores()->exists()) {
      return response()->json([
        'status'  => 'error',
        'message' => 'No se puede eliminar el tipo de negocio porque tiene tiendas asociadas.',
        'data'    => null,
        'errors'  => null,
      ], 409);
    }

    $businessType->delete();

    return response()->json([
      'status'  => 'success',
      'message' => 'Tipo de negocio eliminado correctamente.',
      'data'    => null,
      'errors'  => null,
    ]);
  }
}
