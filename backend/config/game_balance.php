<?php

return [
    'default_balance_version' => '1.0',

    'expeditions' => [
        'surface' => [
            'duration_seconds' => 300,
            'energy_per_resident' => '1.00',
            'cost' => [
                'fog' => '15.000000',
                'silence' => '0.000000',
            ],
            'memories_min' => 5,
            'memories_max' => 10,
        ],
        'deep' => [
            'duration_seconds' => 2700,
            'energy_per_resident' => '3.00',
            'cost' => [
                'fog' => '40.000000',
                'silence' => '10.000000',
            ],
            'memories_min' => 20,
            'memories_max' => 35,
        ],
    ],

    'alchemy' => [
        'protected_categories' => ['critical'],
        'protected_component_types' => ['metaphor', 'critical_metaphor'],
        'failed_experiment_consumes_base_fog' => '5.000000',
    ],
];
