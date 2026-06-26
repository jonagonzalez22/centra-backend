<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    title: 'User',
    description: 'Modelo de usuario de CENTRA',
    type: 'object'
)]
class UserSchema
{
    #[OA\Property(property: 'id', type: 'string', example: '123e4567-e89b-12d3-a456-426614174000')]
    public int $id;

    #[OA\Property(property: 'name', type: 'string', example: 'Admin Centra')]
    public string $name;

    #[OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@centra.com')]
    public string $email;

    #[OA\Property(property: 'is_active', type: 'boolean', example: true)]
    public bool $is_active;

    #[OA\Property(
        property: 'store',
        ref: '#/components/schemas/StoreLight'
    )]
    public $store;

    #[OA\Property(
        property: 'roles',
        type: 'array',
        items: new OA\Items(type: 'string'),
        example: ['admin']
    )]
    public array $roles;

    #[OA\Property(
        property: 'permissions',
        type: 'array',
        items: new OA\Items(type: 'string'),
        example: ['cash.view']
    )]
    public array $permissions;

    #[OA\Property(
        property: 'features',
        type: 'array',
        items: new OA\Items(
            type: 'object',
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'pos'),
                new OA\Property(property: 'limit', type: 'integer', nullable: true, example: null),
            ]
        ),
        example: [['code' => 'pos', 'limit' => null], ['code' => 'multi_user', 'limit' => 2]]
    )]
    public array $features;
}
