<?php

namespace App\Enums;

enum EstadoUsuario: string
{
    case Activo = 'activo';
    case Suspendido = 'suspendido';
}
