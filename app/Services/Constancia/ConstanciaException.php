<?php

namespace App\Services\Constancia;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * La constancia no se pudo usar. El mensaje ya va redactado para el usuario.
 */
class ConstanciaException extends Exception
{
    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }

    public static function qrNoLegible(): self
    {
        return new self('QR_NO_LEGIBLE', 'No se pudo leer el código QR. Intenta con el PDF original, o con una foto más de frente y con buena luz.');
    }

    public static function qrNoOficial(): self
    {
        return new self('QR_NO_OFICIAL', 'El código QR de este documento no apunta al SAT. Verifica que sea una Constancia de Situación Fiscal oficial.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->codigo, 'mensaje' => $this->getMessage()], 422);
    }
}
