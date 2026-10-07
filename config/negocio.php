<?php

/*
 * Datos del negocio que imprime el ticket de pedido (019). Cada dato vacío se
 * omite. El logo es una ruta relativa al disco "public"
 * (storage/app/public), p. ej. "negocio/logo.png"; sin él, el ticket usa el
 * logo de los documentos (public/img/marca).
 *
 * copia_correos (032) recibe copia oculta de cada cotización, orden de compra
 * y factura que sale por correo; vacío, no se manda copia.
 */
return [
    'nombre' => env('NEGOCIO_NOMBRE') ?: env('APP_NAME'),
    'domicilio' => env('NEGOCIO_DOMICILIO', 'Real del Seminario 122, Valle del Real.'),
    'ciudad' => env('NEGOCIO_CIUDAD', 'Celaya, Gto.'),
    'telefono' => env('NEGOCIO_TELEFONO', '4613581090'),
    'sitio_web' => env('NEGOCIO_SITIO_WEB', 'www.sellopronto.com.mx'),
    'logo' => env('NEGOCIO_LOGO'),
    'copia_correos' => env('NEGOCIO_COPIA_CORREOS', 'ventas@sellopronto.com.mx'),
];
