<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTypeResource;
use App\Http\Resources\LocalityResource;
use App\Http\Resources\ProvinceResource;
use App\Models\DocumentType;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CatalogsController extends Controller
{
    #[OA\Get(
        path: '/catalogs/document-types',
        summary: 'Listar tipos de documento',
        description: 'Retorna el catálogo de tipos de documento disponibles en el sistema.',
        operationId: 'catalogDocumentTypes',
        security: [['sanctum' => []]],
        tags: ['Catálogos']
    )]
    #[OA\Response(
        response: 200,
        description: 'Catálogo de tipos de documento obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Tipos de documento obtenidos correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/DocumentType')),
                            ]
                        ),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function documentTypes(): JsonResponse
    {
        $types = DocumentType::orderBy('name')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Tipos de documento obtenidos correctamente.',
            'data' => [
                'items' => DocumentTypeResource::collection($types),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: '/catalogs/provinces',
        summary: 'Listar provincias',
        description: 'Retorna todas las provincias de Argentina ordenadas alfabéticamente.',
        operationId: 'catalogProvinces',
        security: [['sanctum' => []]],
        tags: ['Catálogos']
    )]
    #[OA\Parameter(
        name: 'with_localities_count',
        in: 'query',
        required: false,
        description: 'Incluir conteo de localidades por provincia',
        schema: new OA\Schema(type: 'boolean', example: false)
    )]
    #[OA\Response(
        response: 200,
        description: 'Provincias obtenidas correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Provincias obtenidas correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Province')),
                            ]
                        ),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function provinces(Request $request): JsonResponse
    {
        $query = Province::query()->orderBy('name');

        if ($request->boolean('with_localities_count')) {
            $query->with('localities');
        }

        $provinces = $query->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Provincias obtenidas correctamente.',
            'data' => [
                'items' => ProvinceResource::collection($provinces),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: '/catalogs/provinces/{province}/localities',
        summary: 'Listar localidades por provincia',
        description: 'Retorna todas las localidades de una provincia específica ordenadas alfabéticamente.',
        operationId: 'catalogProvincesLocalities',
        security: [['sanctum' => []]],
        tags: ['Catálogos']
    )]
    #[OA\Parameter(
        name: 'province',
        in: 'path',
        required: true,
        description: 'ID de la provincia',
        schema: new OA\Schema(type: 'string', format: 'uuid')
    )]
    #[OA\Parameter(
        name: 'per_page',
        in: 'query',
        required: false,
        description: 'Cantidad de resultados por página (default: 50)',
        schema: new OA\Schema(type: 'integer', example: 50)
    )]
    #[OA\Parameter(
        name: 'page',
        in: 'query',
        required: false,
        description: 'Número de página',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Localidades obtenidas correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Etiquetas obtenidas correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Locality')),
                                new OA\Property(property: 'total', type: 'integer', example: 202),
                                new OA\Property(property: 'per_page', type: 'integer', example: 50),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 5),
                            ]
                        ),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 404,
        description: 'Provincia no encontrada',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function localities(Request $request, Province $province): JsonResponse
    {
        $perPage = $request->integer('per_page', 50);
        $localities = $province->localities()
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Localidades obtenidas correctamente.',
            'data' => [
                'items' => LocalityResource::collection($localities->items()),
                'total' => $localities->total(),
                'per_page' => $localities->perPage(),
                'current_page' => $localities->currentPage(),
                'last_page' => $localities->lastPage(),
            ],
            'errors' => null,
        ]);
    }
}
