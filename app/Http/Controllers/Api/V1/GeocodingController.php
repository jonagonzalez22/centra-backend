<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GeocodingSearchRequest;
use App\Services\Location\LocationManager;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class GeocodingController extends Controller
{
    public function __construct(
        private readonly LocationManager $locationManager,
    ) {}

    #[OA\Post(
        path: '/geocoding/search',
        summary: 'Geocodificar una dirección',
        description: 'Recibe una dirección y retorna las coordenadas geográficas y la dirección formateada.',
        operationId: 'geocodingSearch',
        security: [['sanctum' => []]],
        tags: ['Geocoding']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['address'],
            properties: [
                new OA\Property(property: 'address', type: 'string', example: 'Av. Colón 77, Córdoba, Argentina'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Dirección geocodificada exitosamente.',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Dirección geocodificada exitosamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'latitude', type: 'float', example: -31.4201),
                                new OA\Property(property: 'longitude', type: 'float', example: -64.1888),
                                new OA\Property(property: 'formatted_address', type: 'string', example: 'Av. Colón 77, Córdoba, X5000JJB, Argentina'),
                                new OA\Property(property: 'provider', type: 'string', example: 'google'),
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
        description: 'No autenticado.',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación.',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 500,
        description: 'Error interno del servidor.',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function search(GeocodingSearchRequest $request): JsonResponse
    {
        try {
            $input = $request->getLocationInput();
            $result = $this->locationManager->resolve($input);

            if ($result === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se pudo resolver la ubicación. Verificá el formato de la dirección o link provisto.',
                    'data' => null,
                    'errors' => ['location' => ['No se encontró un resolutor capaz de procesar el input proporcionado.']],
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Ubicación resuelta exitosamente.',
                'data' => [
                    'latitude' => $result->latitude,
                    'longitude' => $result->longitude,
                    'formatted_address' => $result->formatted_address,
                    'provider' => $result->provider,
                ],
                'errors' => null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo resolver la ubicación.',
                'data' => null,
                'errors' => ['location' => [$e->getMessage()]],
            ], 500);
        }
    }
}
