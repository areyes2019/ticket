<?php

namespace App\Http\Requests\Concerns;

trait NormalizaTelefono
{
    /**
     * Convierte el teléfono a +52 y 10 dígitos. Es idempotente: un valor ya
     * normalizado (+524491234567) queda igual. Si no son 10 dígitos, se deja
     * como se escribió para que la validación lo rechace.
     */
    protected function normalizarTelefono(string $telefono): string
    {
        $digitos = preg_replace('/\D/', '', $telefono);

        if (strlen($digitos) === 12 && str_starts_with($digitos, '52')) {
            $digitos = substr($digitos, 2);
        }

        return strlen($digitos) === 10 ? '+52'.$digitos : $telefono;
    }
}
