<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vehicle Types Catalog
    |--------------------------------------------------------------------------
    |
    | Valid vehicle type codes. Used for validation and as a reference catalog
    | exposed via the store vehicle-catalogs endpoint.
    |
    */
    'types' => [
        'auto',
        'moto',
        'bicicleta',
        'camioneta',
        'camion',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inactivation Reason Codes
    |--------------------------------------------------------------------------
    |
    | Valid reason codes when deactivating a vehicle. The 'other' code triggers
    | a required inactivation_notes field via FormRequest validation.
    |
    */
    'inactivation_reasons' => [
        'maintenance',
        'repair',
        'accident',
        'unavailable',
        'other',
    ],

];
