<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocalityResource;
use App\Http\Resources\ProvinceResource;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class GeographyController extends Controller
{
    #[OA\Get(
        path: '/geography/provinces',
        summary: 'Listar provincias',
        description: 'Retorna todas las provincias de Argentina.',
        operationId: 'geographyProvincesIndex',
        security: [['sanctum' => []]],
        tags: ['Geography']
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 200,
        description: 'Provincias obtenidas correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Province')
                        ),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    public function provinces(Request $request): JsonResponse
    {
        $query = Province::query()->orderBy('name');

        if ($request->boolean('with_localities_count')) {
            $query->withCount('localities');
        }

        $provinces = $query->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Provincias obtenidas correctamente.',
            'data' => ProvinceResource::collection($provinces),
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: '/geography/provinces/{id}/localities',
        summary: 'Listar localidades por provincia',
        description: 'Retorna todas las localidades de una provincia específica.',
        operationId: 'geographyProvincesLocalities',
        security: [['sanctum' => []]],
        tags: ['Geography']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID de la provincia',
        schema: new OA\Schema(type: 'string')
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
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 404,
        description: 'Provincia no encontrada',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 200,
        description: 'Localidades obtenidas correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Locality')
                                ),
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
    public function localities(Request $request, string $id): JsonResponse
    {
        $province = Province::findOrFail($id);

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
