<?php

/*
 * Datos del negocio que imprime el ticket de pedido (019). Cada dato vacío se
 * omite. El logo es una ruta relativa al disco "public"
 * (storage/app/public), p. ej. "negocio/logo.png".
 */
return [
    'nombre' => env('NEGOCIO_NOMBRE') ?: env('APP_NAME'),
    'telefono' => env('NEGOCIO_TELEFONO'),
    'domicilio' => env('NEGOCIO_DOMICILIO'),
    'logo' => env('NEGOCIO_LOGO'),
];
