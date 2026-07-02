<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTypeResource;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CatalogController extends Controller
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
}
