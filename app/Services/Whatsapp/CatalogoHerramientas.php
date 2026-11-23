<?php

namespace App\Services\Whatsapp;

use App\Enums\AgenteWhatsapp;
use App\Services\Whatsapp\Herramientas\BuscarArticulos;
use App\Services\Whatsapp\Herramientas\BuscarVenta;
use App\Services\Whatsapp\Herramientas\CrearCotizacion;
use App\Services\Whatsapp\Herramientas\DarDatosBancarios;
use App\Services\Whatsapp\Herramientas\DatosFiscalesGuardados;
use App\Services\Whatsapp\Herramientas\EnviarCotizacion;
use App\Services\Whatsapp\Herramientas\Herramienta;
use App\Services\Whatsapp\Herramientas\LeerConstancia;
use App\Services\Whatsapp\Herramientas\PasarAHumano;
use App\Services\Whatsapp\Herramientas\ReenviarFactura;
use App\Services\Whatsapp\Herramientas\TimbrarFactura;
use App\Services\Whatsapp\Herramientas\Transferir;

/**
 * Qué herramientas tiene cada agente (041, tabla de la regla 52). Lo que no
 * está aquí, el agente no lo puede hacer.
 */
class CatalogoHerramientas
{
    /**
     * @return array<string, Herramienta> por nombre
     */
    public function de(AgenteWhatsapp $agente): array
    {
        $herramientas = match ($agente) {
            AgenteWhatsapp::Ventas => [
                app(BuscarArticulos::class),
                app(CrearCotizacion::class),
                app(EnviarCotizacion::class),
                app(DarDatosBancarios::class),
                app(PasarAHumano::class),
                new Transferir(AgenteWhatsapp::Facturacion),
            ],
            AgenteWhatsapp::Facturacion => [
                app(BuscarVenta::class),
                app(DatosFiscalesGuardados::class),
                app(LeerConstancia::class),
                app(TimbrarFactura::class),
                app(ReenviarFactura::class),
                app(PasarAHumano::class),
                new Transferir(AgenteWhatsapp::Ventas),
            ],
        };

        $porNombre = [];

        foreach ($herramientas as $herramienta) {
            $porNombre[$herramienta->nombre()] = $herramienta;
        }

        return $porNombre;
    }
}
