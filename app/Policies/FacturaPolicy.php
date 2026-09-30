<?php

namespace App\Policies;

use App\Enums\EstadoFactura;
use App\Models\Factura;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Una factura ajena responde 404 en todas las acciones. Las reglas de estado
 * viven en el modelo; aquí solo se convierten en una respuesta con motivo.
 */
class FacturaPolicy
{
    public function view(User $user, Factura $factura): Response
    {
        return $this->esDueno($user, $factura);
    }

    public function update(User $user, Factura $factura): Response
    {
        $dueno = $this->esDueno($user, $factura);

        if ($dueno->denied()) {
            return $dueno;
        }

        return match (true) {
            $factura->esEditable() => Response::allow(),
            $factura->estado === EstadoFactura::Pendiente => Response::deny('El timbrado falló por facturapi.io, no por los datos: reintenta el timbrado sin cambios.'),
            default => Response::deny('Una factura '.mb_strtolower($factura->estado->etiqueta()).' no se puede modificar.'),
        };
    }

    public function delete(User $user, Factura $factura): Response
    {
        $dueno = $this->esDueno($user, $factura);

        if ($dueno->denied()) {
            return $dueno;
        }

        return $factura->puedeEliminarse()
            ? Response::allow()
            : Response::deny('Una factura '.mb_strtolower($factura->estado->etiqueta()).' no se puede eliminar.');
    }

    /**
     * Timbrar, cancelar, XML, PDF, enviar y complemento de pago: la regla de
     * estado de cada acción la revisa su controlador.
     */
    public function operar(User $user, Factura $factura): Response
    {
        return $this->esDueno($user, $factura);
    }

    private function esDueno(User $user, Factura $factura): Response
    {
        return $factura->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
