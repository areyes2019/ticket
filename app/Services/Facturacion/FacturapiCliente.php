<?php

namespace App\Services\Facturacion;

use App\Enums\MotivoCancelacion;
use App\Enums\TipoErrorTimbrado;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Único punto que habla con facturapi.io. Cualquier falla lanza
 * FacturapiException: 4xx es un error de datos (se corrigen y se reintenta);
 * 5xx, timeout o conexión es un error del PAC (se reintenta igual).
 *
 * Los registros nunca llevan la llave ni el payload completo.
 */
class FacturapiCliente
{
    /**
     * POST /invoices: crea y timbra en un solo paso (sin status: draft).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function crearFactura(array $payload, ?int $facturaId = null): array
    {
        return $this->llamar('crear factura', $facturaId, fn (PendingRequest $http) => $http->acceptJson()->post('invoices', $payload))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarFactura(string $id, ?int $facturaId = null): array
    {
        return $this->llamar('consultar factura', $facturaId, fn (PendingRequest $http) => $http->acceptJson()->get('invoices/'.$id))->json();
    }

    /**
     * DELETE /invoices/{id}?motive=XX[&substitution=…]. La respuesta trae el
     * cancellation_status.
     *
     * @return array<string, mixed>
     */
    public function cancelarFactura(string $id, MotivoCancelacion $motivo, ?string $sustitucion = null, ?int $facturaId = null): array
    {
        $consulta = http_build_query(array_filter(['motive' => $motivo->value, 'substitution' => $sustitucion]));

        return $this->llamar('cancelar factura', $facturaId, fn (PendingRequest $http) => $http->acceptJson()->delete('invoices/'.$id.'?'.$consulta))->json();
    }

    /**
     * CFDI creado con este external_id (el más reciente), o null si no hay.
     *
     * @return array<string, mixed>|null
     */
    public function buscarPorReferencia(string $referencia, ?int $facturaId = null): ?array
    {
        $encontradas = $this->llamar('buscar por referencia', $facturaId, fn (PendingRequest $http) => $http->acceptJson()->get('invoices', ['external_id' => $referencia]))->json('data') ?? [];

        return $encontradas === [] ? null : $this->consultarFactura($encontradas[0]['id'], $facturaId);
    }

    public function descargarXml(string $id, ?int $facturaId = null): string
    {
        return $this->llamar('descargar XML', $facturaId, fn (PendingRequest $http) => $http->accept('application/xml')->get('invoices/'.$id.'/xml'))->body();
    }

    /**
     * Un complemento de pago también es un CFDI (type P) creado con POST /invoices.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function crearComplementoPago(array $payload, ?int $facturaId = null): array
    {
        return $this->llamar('crear complemento de pago', $facturaId, fn (PendingRequest $http) => $http->acceptJson()->post('invoices', $payload))->json();
    }

    /**
     * @param  Closure(PendingRequest): Response  $peticion
     */
    private function llamar(string $operacion, ?int $facturaId, Closure $peticion): Response
    {
        $llave = config('services.facturapi.llave');
        $segundos = (int) config('services.facturapi.timeout');

        if (blank($llave)) {
            $this->registrar($operacion, $facturaId, null, 'Sin llave configurada');

            throw new FacturapiException('Falta configurar la llave de facturapi.io en el servidor (FACTURAPI_TEST_KEY o FACTURAPI_LIVE_KEY).', TipoErrorTimbrado::Pac);
        }

        try {
            $respuesta = $peticion(Http::baseUrl(config('services.facturapi.url'))->withToken($llave)->timeout($segundos));
        } catch (ConnectionException $error) {
            $this->registrar($operacion, $facturaId, null, $error->getMessage());

            $mensaje = str_contains(mb_strtolower($error->getMessage()), 'timed out')
                ? "facturapi.io no respondió en {$segundos} segundos."
                : 'No se pudo conectar con facturapi.io.';

            throw new FacturapiException($mensaje, TipoErrorTimbrado::Pac);
        }

        if ($respuesta->failed()) {
            $mensaje = (string) ($respuesta->json('message') ?: 'facturapi.io respondió con un error ('.$respuesta->status().').');
            $this->registrar($operacion, $facturaId, $respuesta->status(), $mensaje);

            throw new FacturapiException($mensaje, $respuesta->clientError() ? TipoErrorTimbrado::Datos : TipoErrorTimbrado::Pac, $respuesta->status());
        }

        return $respuesta;
    }

    private function registrar(string $operacion, ?int $facturaId, ?int $estado, string $mensaje): void
    {
        Log::warning('facturapi', [
            'operacion' => $operacion,
            'factura_id' => $facturaId,
            'status' => $estado,
            'mensaje' => $mensaje,
        ]);
    }
}
