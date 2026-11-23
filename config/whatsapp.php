<?php

/*
 * Agentes de WhatsApp (041). Los datos de conexión de Meta viven en la tabla
 * whatsapp_numeros (cifrados); aquí solo lo que es de la instalación.
 */
return [
    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v24.0'),

    'claude' => [
        'modelo' => env('WHATSAPP_CLAUDE_MODELO', 'claude-opus-5-5'),
        'effort' => env('WHATSAPP_CLAUDE_EFFORT', 'low'),
        'max_tokens' => (int) env('WHATSAPP_CLAUDE_MAX_TOKENS', 4000),
        // Respaldo del servidor ante rechazos (beta). false lo apaga.
        'fallbacks' => (bool) env('WHATSAPP_CLAUDE_FALLBACKS', true),

        // US$ por millón de tokens, para el costo estimado (regla 72).
        'precios' => [
            'claude-opus-5-5' => ['entrada' => 4.00, 'salida' => 20.00, 'cache_lectura' => 0.20, 'cache_escritura' => 5.00],
            'claude-sonnet-5-5' => ['entrada' => 2.00, 'salida' => 10.00, 'cache_lectura' => 0.10, 'cache_escritura' => 2.50],
            'claude-haiku-5-5' => ['entrada' => 0.10, 'salida' => 0.50, 'cache_lectura' => 0.01, 'cache_escritura' => 0.125],
        ],
    ],

    // Regla 75: mensajes de texto que ve el agente; el resto va en el resumen.
    'mensajes_historial' => 40,
    'resumir_cada' => 20,

    // Reglas 19 y 58.
    'max_mb_archivo' => 10,

    // Regla 26.
    'max_mensajes_turno' => 5,
    'max_herramientas_turno' => 10,
];
