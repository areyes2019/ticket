<?php

namespace App\Policies;

use App\Models\Cotizacion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Una cotización ajena responde 404 en todas las acciones. Las reglas de
 * estado (qué se puede hacer en cada momento) viven en el modelo; aquí solo se
 * convierten en una respuesta con motivo.
 */
class CotizacionPolicy
{
    public function view(User $user, Cotizacion $cotizacion): Response
    {
        return $this->esDueno($user, $cotizacion);
    }

    public function update(User $user, Cotizacion $cotizacion): Response
    {
        $dueno = $this->esDueno($user, $cotizacion);

        if ($dueno->denied()) {
            return $dueno;
        }

        return match (true) {
            $cotizacion->esEditable() => Response::allow(),
            $cotizacion->estaFacturada() => Response::deny('Una cotización facturada no se puede modificar.'),
            $cotizacion->estaAceptada() => Response::deny($this->motivoAceptada($cotizacion)),
            default => Response::deny('Una cotización '.mb_strtolower($cotizacion->estado->etiqueta()).' ya no se puede editar.'),
        };
    }

    public function delete(User $user, Cotizacion $cotizacion): Response
    {
        $dueno = $this->esDueno($user, $cotizacion);

        if ($dueno->denied()) {
            return $dueno;
        }

        return match (true) {
            $cotizacion->estaFacturada() => Response::deny('Una cotización facturada no se puede eliminar.'),
            $cotizacion->venta?->cobro_en_cotizacion => Response::deny("La cotización tiene la venta {$cotizacion->venta->folio_formateado}: bórrala antes."),
            $cotizacion->estaAceptada() => Response::deny($this->motivoAceptada($cotizacion)),
            ! $cotizacion->esEditable() => Response::deny('Una cotización '.mb_strtolower($cotizacion->estado->etiqueta()).' no se puede eliminar.'),
            $cotizacion->tienePagos() => Response::deny('La cotización tiene pagos registrados: elimínalos antes de borrarla.'),
            default => Response::allow(),
        };
    }

    /**
     * Enviar, compartir, pagar, entregar, aceptar, duplicar, facturar y descargar el PDF: las
     * reglas de estado de cada acción las revisa su controlador.
     */
    public function operar(User $user, Cotizacion $cotizacion): Response
    {
        return $this->esDueno($user, $cotizacion);
    }

    /**
     * La de "Aceptar" (021) se corrige en su venta. La que cobra aquí (029)
     * se edita hasta que su venta se entrega o se factura.
     */
    private function motivoAceptada(Cotizacion $cotizacion): string
    {
        $venta = $cotizacion->venta;

        return match (true) {
            $venta === null => 'Una cotización aceptada ya no se modifica.',
            ! $venta->cobro_en_cotizacion => "Una cotización aceptada ya no se modifica: corrige la venta {$venta->folio_formateado}.",
            $venta->estaEntregado() => "La venta {$venta->folio_formateado} ya se entregó: la cotización queda solo para consulta.",
            $venta->facturaVigente !== null => "Ya tiene la factura {$venta->facturaVigente->folioVisible()}.",
            default => 'Una cotización aceptada ya no se modifica.',
        };
    }

    private function esDueno(User $user, Cotizacion $cotizacion): Response
    {
        return $cotizacion->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
